<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Application\Service\Runtime;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\Environment;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Lifecycle\CurrentRequestStore;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Pipeline\ReRun\ReRunContext;
use Semitexa\Core\Server\SwooleBootstrap;
use Semitexa\Graphql\Application\Payload\Request\GraphqlEndpointPayload;
use Semitexa\Graphql\Application\Resource\Response\GraphqlEndpointResource;
use Semitexa\Graphql\Domain\Contract\GraphqlExecutorInterface;
use Semitexa\Graphql\Domain\Contract\GraphqlOperationRegistryInterface;
use Semitexa\Graphql\Domain\Model\GraphqlSseMode;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
use Semitexa\Ssr\Domain\Model\SubscriptionRecord;

/**
 * The dual-mode SSE fork for the single `POST /graphql` endpoint (PHASE 1).
 *
 * This is the thin, SSE-aware seam the {@see \Semitexa\Graphql\Application\Handler\PayloadHandler\GraphqlEndpointHandler}
 * consults BEFORE its unchanged one-shot branch. It returns:
 *
 *   - a held-open `text/event-stream` Resource  → when the caller negotiated
 *     `Accept: text/event-stream` AND the operation is a `subscription`;
 *   - `null`                                    → in every other case (no SSE
 *     header; a query/mutation even WITH the header; a malformed document; or
 *     no live Swoole socket), so the handler runs the existing one-shot path
 *     completely unchanged.
 *
 * Transport reuse, not reinvention. The held-open machinery is the SAME one the
 * declarative grid feed uses: grab the raw Swoole pair the established way
 * ({@see SwooleBootstrap::getCurrentSwooleRequestResponse()}, as the grid /
 * KISS handlers do), then hand the live socket to
 * {@see AsyncResourceSseServer::serveResourceStream()} — which writes the SSE
 * headers, the first frame, and holds the fd open in the framework drain loop.
 * No new streaming loop is written here.
 *
 * SSR is a SOFT dependency. The GraphQL package's core function is
 * request-response; streaming is an optional enhancement. So the SSR /
 * Swoole classes are referenced behind `class_exists()` guards rather than a
 * hard composer require — without them (or without a live socket) the seam
 * simply returns `null` and the endpoint stays byte-identically one-shot.
 *
 * PHASE 2 scope: the stream emits ONE *real, executed* graphql-sse `next` frame
 * and then holds open. The subscription document is executed ONE-SHOT
 * (query-style) through the SAME {@see GraphqlExecutorInterface::execute()} a
 * query uses — webonyx resolves a subscription operation's root fields exactly
 * like a query (it does NOT engage `createSourceEventStream`), so the frame
 * carries a real `{data, errors}` ExecutionResult body. The frame is shaped as
 * an SSR passthrough frame (the `_sse_event` key, recognised by
 * {@see \Semitexa\Ssr\Application\Service\Async\SsePassthroughEvent}) so the
 * wire body is BARE — `event: next` + `{"data":…,"errors":[…]}` with no in-body
 * discriminator. It still registers NO
 * {@see \Semitexa\Ssr\Domain\Model\SubscriptionRecord} and NO re-run context
 * (both passed `null`): the stream pushes the initial frame and holds open, but
 * does NOT yet react to writes — the Redis re-run source is Phase 4. The
 * declared `watchScopes` are likewise not consumed until Phase 4.
 *
 * PHASE 3 — the admission gate. Inside the matched SSE branch (and ONLY there;
 * every `return null` fall-through above stays byte-identical) a config-selected
 * mode ({@see GraphqlSseMode}: `disabled` / `authenticated-only` (default) /
 * `everyone`, env `SEMITEXA_GRAPHQL_SSE_MODE`) is enforced BEFORE the socket
 * grab — once `serveResourceStream()` writes the 200 + SSE headers a refusal is
 * impossible, so the gate is the last check ahead of it. Authenticated-vs-guest
 * is the SAME answer the framework auth pipeline already produced for this
 * request: `/graphql` is `#[AsPublicPayload]`, so the AuthorizationListener ran
 * the AuthBootstrapper in BestEffort mode and populated the request's
 * {@see AuthContextInterface} (the identical mechanism the grid SSE admit chain
 * — `SseGateModel::BearerSession` in `AsyncResourceSseServer::handleSse` —
 * resolves its `authenticated` flag from; no auth is reinvented here). An
 * inadmissible request is refused with an explicit HTTP-level error — a small
 * transport JSON body, NOT a GraphQL `{data,errors}` envelope — emitted through
 * the normal one-shot emitter (no `markAsAlreadySent()`):
 *
 *   - `disabled`             → 400 `SSE_DISABLED`  (any caller; 405 was
 *     rejected: POST /graphql is a valid method — the one-shot path serves it —
 *     and 403 is reserved for the auth refusal; operator may flip to 405 by
 *     changing one constant);
 *   - `authenticated-only` + guest → 403 `SSE_FORBIDDEN`.
 *
 * PHASE 4 — the live re-run source. An admitted stream now registers with the
 * EXISTING Track-R apparatus, exactly as a grid feed does (`ConnectCoordinator`
 * via `serveResourceStream(record, context)` — registration AND disconnect
 * cleanup live there already; nothing is duplicated here):
 *
 *   - the cross-worker {@see SubscriptionRecord} carries `scopeKeys` = the
 *     union of the matched subscription operations' declared
 *     `#[ExposeAsGraphql(watchScopes:)]` — the FIRST consumption of
 *     `watchScopes`. An operation declaring none registers no scopes (held
 *     open, never re-run) and logs a structured warning;
 *   - the worker-local re-run state is core's standard {@see ReRunContext},
 *     mirroring the grid discipline verbatim: `cachedDto` = THIS payload (the
 *     parsed-shape carrier — query / variables / operationName — never an
 *     identity source), the connect-time request snapshot (identity is
 *     re-resolved from the live session each tick), `subjectRef` from the
 *     auth context, `tenantContext` null (the R4 re-run shares the connect
 *     coroutine, whose tenant context is already established — passing one
 *     makes the store throw; the record still carries tenant id/blob for
 *     channel scoping). No GraphQL-specific context CLASS exists on purpose:
 *     the tier-2 registry (`SubscriptionDtoRegistry`) is typed to core's final
 *     `ReRunContext`, and the GraphQL-ness lives entirely in the cached DTO +
 *     route.
 *
 * On a `ui.invalidate.{tenant}.{scope}` publish the held-open loop re-runs the
 * frozen route chain auth-first ({@see \Semitexa\Core\Pipeline\ReRun\RouteReRunner}
 * → `RouteExecutor::reExecute()` → this very handler again). The re-run tick is
 * recognised HERE via {@see AsyncResourceSseServer::isReRunInProgress()} — the
 * same own-route convention the grid feed handler uses — and answered with a
 * ONE-SHOT JSON body shaped `{_sse_event:'next', data, errors}`: the held-open
 * loop decodes it ({@see AsyncResourceSseServer} `reRunFrameData()`) and funnels
 * it through the SAME `buildFrame()` chokepoint as the initial frame, where the
 * Phase-2 passthrough emits `event: next` + the BARE `{data,errors}` body. One
 * funnel, zero SSR changes. Execution errors on a tick ride INSIDE the `next`
 * body (graphql-sse semantics), exactly as one-shot; a lost-access tick
 * TERMINATEs upstream (close frame, no data) before this branch is reached.
 */
#[AsService]
final class GraphqlSseSubscriptionStreamer
{
    /**
     * The SSR passthrough-frame key. Referenced as a LITERAL (not an import of
     * {@see \Semitexa\Ssr\Application\Service\Async\SsePassthroughEvent::KEY})
     * so the soft `graphql → ssr` dependency holds — SSR owns the canonical
     * definition; this is the consumer side of the shared wire contract.
     */
    private const SSE_PASSTHROUGH_EVENT_KEY = '_sse_event';

    /**
     * Refusal HTTP statuses (PHASE 3). `DISABLED_STATUS` is the operator's
     * one-line flip point should they prefer 405 over 400.
     */
    private const DISABLED_STATUS = HttpStatus::BadRequest;
    private const FORBIDDEN_STATUS = HttpStatus::Forbidden;

    /** Transport-level refusal codes — one distinct code per refusal reason. */
    private const CODE_SSE_DISABLED = 'SSE_DISABLED';
    private const CODE_SSE_FORBIDDEN = 'SSE_FORBIDDEN';

    #[InjectAsReadonly]
    protected GraphqlOperationTypeResolver $operationType;

    #[InjectAsReadonly]
    protected GraphqlExecutorInterface $executor;

    /** Source of the matched subscription operations' declared `watchScopes`. */
    #[InjectAsReadonly]
    protected GraphqlOperationRegistryInterface $operations;

    /** Resolves THIS route (with handlers) for the frozen re-run chain. */
    #[InjectAsReadonly]
    protected RouteRegistry $routeRegistry;

    #[InjectAsReadonly]
    protected AttributeDiscovery $attributeDiscovery;

    /**
     * The request's auth answer, as produced by the framework auth pipeline
     * (BestEffort on this public route). Nullable best-effort injection: the
     * binding lives in `semitexa-auth` (`AuthManager`, coroutine-scoped store),
     * which — like SSR — is not a hard dependency of this package. Absent
     * binding ⇒ no way to prove authentication ⇒ the gate treats every caller
     * as guest (fail-closed for `authenticated-only`, irrelevant for the other
     * two modes).
     */
    #[InjectAsReadonly]
    protected ?AuthContextInterface $authContext = null;

    /**
     * Attempt to serve the request as a held-open GraphQL subscription stream.
     * Returns the already-sent Resource when streaming was served, or `null`
     * when the caller must fall through to the unchanged one-shot branch.
     */
    public function tryServe(
        GraphqlEndpointPayload $payload,
        GraphqlEndpointResource $resource,
    ): ?GraphqlEndpointResource {
        // No SSR / Swoole runtime → nothing to stream onto. (Soft dependency.)
        if (!class_exists(SwooleBootstrap::class) || !class_exists(AsyncResourceSseServer::class)) {
            return null;
        }

        // RE-RUN TICK (PHASE 4): a `{__ctrl:rerun}` re-ran this frozen route
        // chain — the live fd is already held and being streamed on by the
        // loop that triggered us, so the socket must NOT be touched (the same
        // own-route convention as the grid feed handler). Re-execute the
        // cached subscription document one-shot and answer with the framed
        // `next` BODY as a normal JSON resource; the held-open loop decodes it
        // and writes it through the single buildFrame() funnel.
        if (AsyncResourceSseServer::isReRunInProgress()) {
            if (!$this->operationType->isSubscription($payload->getQuery(), $payload->getOperationName())) {
                return null;
            }
            $resource->setRenderContext($this->nextFrame($payload));

            return $resource;
        }

        // The raw Swoole pair is the only way to read the negotiated Accept
        // header and to obtain the held fd — same access pattern as the grid /
        // KISS handlers. No live socket (e.g. in-process tests) → one-shot.
        $context = SwooleBootstrap::getCurrentSwooleRequestResponse();
        if ($context === null || ($context[1] ?? null) === null || ($context[2] ?? null) === null) {
            return null;
        }
        [$swooleRequest, $swooleResponse, $server] = $context;

        // Opt-in negotiation: the caller must ask for an event stream. Checked
        // BEFORE parsing so a normal (no-SSE-header) request does ZERO extra
        // work and stays byte-identically one-shot.
        $accept = $swooleRequest->header['accept'] ?? '';
        if (!is_string($accept) || !str_contains(strtolower($accept), 'text/event-stream')) {
            return null;
        }

        // The fork keys on operation TYPE, not the header alone: a query /
        // mutation sent WITH the SSE header still falls through to one-shot, and
        // so does a malformed document (parser error surfaced normally there).
        if (!$this->operationType->isSubscription($payload->getQuery(), $payload->getOperationName())) {
            return null;
        }

        // --- PHASE 3 admission gate (matched SSE branch only) ----------------
        // Decided BEFORE the socket grab / any header write: a refusal must be
        // a clean 400/403, never a 200 stream that then errors. A refused
        // Resource is returned non-null (the handler must NOT fall through to
        // one-shot) and is NOT markAsAlreadySent() — the normal emitter sends it.
        $mode = $this->resolveMode();
        if (!$mode->admits($this->isAuthenticated())) {
            return $this->refuse($resource, $mode);
        }

        // --- SSE branch: held-open live stream (PHASE 4) ---------------------
        // Reuse the grid's held-open serve verbatim. The record + context
        // register the stream with the existing Track-R apparatus (the
        // coordinator subscribes the worker to `ui.invalidate.{tenant}.{scope}`
        // per declared watchScope; disconnect cleanup is the serve's finally).
        // When the route / request snapshot cannot be resolved the stream
        // still holds open, just without a live re-run source (null/null) —
        // the grid handler's exact degrade.
        $streamId = AsyncResourceSseServer::mintStreamId();
        $reRunContext = $this->buildReRunContext($payload, $streamId);
        $record = $reRunContext === null ? null : $this->buildSubscriptionRecord($payload, $streamId);

        AsyncResourceSseServer::setServer($server);
        AsyncResourceSseServer::serveResourceStream(
            $swooleRequest,
            $swooleResponse,
            $streamId,
            $this->nextFrame($payload),
            $record,
            $reRunContext,
            $streamId,
        );

        // The SSE server now owns the raw Swoole Response end-to-end (status,
        // headers, frames, end()). Mark already-sent so the framework emitter
        // skips its own status/header/end pass (a second pass SIGSEGVs Swoole).
        $resource->setContent('');
        $resource->markAsAlreadySent();

        return $resource;
    }

    /**
     * Resolve the operator-selected admission mode, emitting the visibility
     * warning when the env value is unrecognised (the resolution itself fails
     * safe to `disabled` inside {@see GraphqlSseMode::fromEnvValue()}).
     */
    private function resolveMode(): GraphqlSseMode
    {
        $raw = Environment::getEnvValue(GraphqlSseMode::ENV_KEY);

        if (!GraphqlSseMode::isRecognized($raw)) {
            StaticLoggerBridge::warning('graphql', sprintf(
                'Unrecognised %s value "%s" — failing safe to "%s". Valid values: %s.',
                GraphqlSseMode::ENV_KEY,
                (string) $raw,
                GraphqlSseMode::Disabled->value,
                implode(' | ', array_column(GraphqlSseMode::cases(), 'value')),
            ));
        }

        return GraphqlSseMode::fromEnvValue($raw);
    }

    /**
     * Authenticated-vs-guest for THIS request, read from the auth context the
     * framework pipeline populated (BestEffort, since the route is public) —
     * the same answer the grid SSE admit chain gates on. No context bound ⇒
     * guest (fail-closed).
     */
    private function isAuthenticated(): bool
    {
        return $this->authContext !== null && !$this->authContext->isGuest();
    }

    /**
     * The PHASE 3 third outcome: refuse the SSE-subscription request with an
     * explicit HTTP-level error before any stream state exists. The body is a
     * small TRANSPORT-level JSON (`{error, code}`) — deliberately not a GraphQL
     * ExecutionResult envelope: the refusal is a transport/security decision,
     * not a GraphQL execution outcome. Rendered by the normal emitter (the
     * Resource is not marked already-sent), which falls back to JSON for an
     * `Accept: text/event-stream` request exactly as the one-shot path does.
     */
    private function refuse(GraphqlEndpointResource $resource, GraphqlSseMode $mode): GraphqlEndpointResource
    {
        if ($mode === GraphqlSseMode::Disabled) {
            $resource->setStatusCode(self::DISABLED_STATUS->value);
            $resource->setRenderContext([
                'error' => 'GraphQL subscriptions over SSE are disabled on this server.',
                'code' => self::CODE_SSE_DISABLED,
            ]);

            return $resource;
        }

        $resource->setStatusCode(self::FORBIDDEN_STATUS->value);
        $resource->setRenderContext([
            'error' => 'Authentication is required for GraphQL subscriptions over SSE.',
            'code' => self::CODE_SSE_FORBIDDEN,
        ]);

        return $resource;
    }

    /**
     * The graphql-sse `next` frame: execute the subscription document ONE-SHOT
     * (query-style) through the same executor a query uses, then frame the
     * resulting `ExecutionResult` as an SSR passthrough frame. Used for BOTH
     * the initial connect frame and every re-run tick's body (the grid's
     * `frameData()` discipline), so the two are shaped identically.
     *
     * graphql-sse "distinct connection mode" carries an `ExecutionResult`
     * (`{data, errors[, extensions]}`) on an `event: next` line. Using the
     * `_sse_event` passthrough key (vs the legacy in-body `event` key) makes the
     * SSR chokepoint emit `event: next` AND strip the discriminator, so the wire
     * body is the BARE ExecutionResult `{"data":…,"errors":[…]}` — conformant,
     * no self-describing key leaking into the body. `extensions` is passed
     * through verbatim only when the result actually carries it.
     *
     * @return array<string, mixed>
     */
    private function nextFrame(GraphqlEndpointPayload $payload): array
    {
        $result = $this->executor->execute(
            $payload->getQuery(),
            $payload->getVariables(),
            $payload->getOperationName(),
        );

        // Build from toArray() so the framed body and the one-shot JSON body
        // are the SAME spec envelope — `errors` must not be present when no
        // errors occurred (GraphQL spec), and `extensions` rides only when set.
        return [self::SSE_PASSTHROUGH_EVENT_KEY => 'next'] + $result->toArray();
    }

    /**
     * Build the worker-local re-run state — core's standard {@see ReRunContext},
     * the grid feed handler's discipline verbatim. `cachedDto` is THIS payload
     * (the unchanged request shape: document / variables / operationName —
     * never an identity source); the request snapshot is the connect-time
     * auth-bearing request, so identity is re-resolved from the live session
     * on every tick; `tenantContext` stays null (the re-run shares the connect
     * coroutine, whose tenant context is already established and immutable).
     * Null when the route or the current request cannot be resolved — the
     * stream then holds open without a live re-run source.
     */
    private function buildReRunContext(GraphqlEndpointPayload $payload, string $streamId): ?ReRunContext
    {
        $request = CurrentRequestStore::get();
        if ($request === null) {
            return null;
        }

        $route = $this->routeRegistry->findRouteTyped(
            $request->getPath(),
            $request->method,
            // WITHOUT the handler registry the route carries no handlers and
            // the re-run pipeline invokes nothing → an empty frame.
            $this->attributeDiscovery->getHandlerRegistry(),
        );
        if ($route === null) {
            return null;
        }

        return new ReRunContext(
            cachedDto: $payload,
            route: $route,
            requestSnapshot: [
                'method'  => $request->method,
                'uri'     => $request->uri,
                'headers' => $request->headers,
                'query'   => $request->query,
                'post'    => $request->post,
                'server'  => $request->server,
                'cookies' => $request->cookies,
                'content' => $request->content,
                'files'   => $request->files,
            ],
            sessionId: $streamId,
            subjectRef: $this->currentSubjectRef(),
            tenantContext: null,
        );
    }

    /**
     * Build the cross-worker subscription row — identity-free and routable,
     * with `scopeKeys` = the union of the matched subscription operations'
     * declared `watchScopes` ({@see resolveWatchScopes()}). streamingId ==
     * sessionId (one stream per connection); tenant id/blob mirror the
     * publisher so the channel names agree.
     */
    private function buildSubscriptionRecord(GraphqlEndpointPayload $payload, string $streamId): SubscriptionRecord
    {
        return new SubscriptionRecord(
            streamingId: $streamId,
            sessionId: $streamId,
            tenantId: $this->currentTenantId(),
            scopeKeys: $this->resolveWatchScopes($payload),
            tenantBlob: $this->currentTenantBlob(),
        );
    }

    /**
     * The FIRST consumption of `#[ExposeAsGraphql(watchScopes:)]`: map the
     * document's subscription root field(s) to their registered operations and
     * union the declared scopes. An empty result is valid — the stream holds
     * open but never re-runs — and is logged as a structured warning so a
     * "subscription that never pushes" is diagnosable, never silent. No
     * default scope is invented.
     *
     * @return list<string>
     */
    private function resolveWatchScopes(GraphqlEndpointPayload $payload): array
    {
        $scopes = [];
        $fields = $this->operationType->subscriptionRootFields(
            $payload->getQuery(),
            $payload->getOperationName(),
        );

        foreach ($fields as $field) {
            $operation = $this->operations->find('subscription', $field);
            foreach ($operation?->watchScopes ?? [] as $scope) {
                $scopes[$scope] = true;
            }
        }

        if ($scopes === []) {
            StaticLoggerBridge::warning('graphql', sprintf(
                'GraphQL SSE subscription registers NO watch scopes (root fields: %s) — '
                . 'the stream holds open but will never live re-run. Declare watchScopes '
                . 'on #[ExposeAsGraphql] to ride resource invalidations.',
                $fields === [] ? '(none resolved)' : implode(', ', $fields),
            ));
        }

        return array_keys($scopes);
    }

    /**
     * The frozen subject reference (the immutable-block anchor each tick's
     * re-auth compares the live session's subject against) — read from the
     * same pipeline-populated auth context the admission gate consumed.
     * Guest / unbound ⇒ '' (the grid handler's identical convention).
     */
    private function currentSubjectRef(): string
    {
        $user = $this->authContext?->getUser();
        if (is_object($user) && method_exists($user, 'getId')) {
            return (string) $user->getId();
        }

        return '';
    }

    /**
     * Tenant id for the cross-worker record — must mirror the publisher's
     * channel naming (`ui.invalidate.{tenant}.{scope}`). Soft tenancy
     * dependency, same defensive resolution as the grid feed handler.
     */
    private function currentTenantId(): string
    {
        $tenant = $this->resolveTenant();
        if (is_object($tenant) && method_exists($tenant, 'getTenantId')) {
            $id = trim((string) $tenant->getTenantId());
            if ($id !== '') {
                return $id;
            }
        }

        return 'default';
    }

    /** Opaque serialized tenant context for cross-worker re-establishment. */
    private function currentTenantBlob(): string
    {
        $tenant = $this->resolveTenant();
        $blob = null;
        if (is_object($tenant) && method_exists($tenant, 'forSerialization')) {
            $blob = $tenant->forSerialization();
        }

        try {
            return json_encode(
                is_array($blob) ? $blob : [],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException) {
            return '{}';
        }
    }

    /** Resolve the live tenant context defensively (null in non-tenancy paths). */
    private function resolveTenant(): ?object
    {
        $ctx = '\Semitexa\Tenancy\Context\TenantContext';
        if (class_exists($ctx) && method_exists($ctx, 'get')) {
            $tenant = $ctx::get();

            return is_object($tenant) ? $tenant : null;
        }

        return null;
    }
}
