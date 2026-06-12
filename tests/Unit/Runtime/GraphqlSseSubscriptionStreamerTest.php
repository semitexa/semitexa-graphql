<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Auth\AuthenticatableInterface;
use Semitexa\Graphql\Application\Payload\Request\GraphqlEndpointPayload;
use Semitexa\Graphql\Application\Resource\Response\GraphqlEndpointResource;
use Semitexa\Graphql\Application\Service\Runtime\GraphqlOperationTypeResolver;
use Semitexa\Graphql\Application\Service\Runtime\GraphqlSseSubscriptionStreamer;
use Semitexa\Graphql\Discovery\ResolvedGraphqlOperation;
use Semitexa\Graphql\Domain\Contract\GraphqlExecutorInterface;
use Semitexa\Graphql\Domain\Contract\GraphqlOperationRegistryInterface;
use Semitexa\Graphql\Domain\Model\GraphqlExecutionResult;
use Semitexa\Graphql\Domain\Model\GraphqlSseMode;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;

/**
 * Unit coverage for the PHASE 2 initial-frame builder and the PHASE 3
 * admission-gate pieces. The held-open serve path needs a live Swoole socket
 * and is exercised in the browser proof-gate; here we assert (a) the frame
 * DATA the streamer hands to `serveResourceStream()` is the passthrough-shaped,
 * real-executed `next` frame (the canned Phase-1 frame is gone), and (b) the
 * gate's refusal Resources are explicit HTTP-level transport errors — correct
 * status, distinct per-reason `code`, NO GraphQL envelope, NOT already-sent.
 * The SSR chokepoint strips `_sse_event` to a bare body — proved separately in
 * `semitexa-ssr` `TypedSseFrameTest`.
 */
final class GraphqlSseSubscriptionStreamerTest extends TestCase
{
    public function test_initial_frame_is_real_executed_passthrough_next_frame(): void
    {
        $streamer = $this->streamerWithExecutor(new GraphqlExecutionResult(
            data: ['articleChanges' => [['id' => 'gql_1', 'title' => 'Hello']]],
            errors: [],
        ));

        $payload = new GraphqlEndpointPayload();
        $payload->setQuery('subscription S { articleChanges { id title } }');
        $payload->setOperationName('S');

        $frame = $this->invokeInitialFrame($streamer, $payload);

        // Passthrough key carries the graphql-sse event name (NOT the legacy
        // in-body `event` key the Phase-1 canned frame used).
        self::assertSame('next', $frame['_sse_event']);
        self::assertArrayNotHasKey('event', $frame);
        // Real executed ExecutionResult body — no Phase-1 canned marker.
        self::assertSame(['articleChanges' => [['id' => 'gql_1', 'title' => 'Hello']]], $frame['data']);
        self::assertSame([], $frame['errors']);
        self::assertArrayNotHasKey('_phase1', $frame['data']);
    }

    public function test_initial_frame_carries_errors_and_null_data(): void
    {
        $streamer = $this->streamerWithExecutor(new GraphqlExecutionResult(
            data: null,
            errors: [['message' => 'boom', 'extensions' => ['code' => 'INTERNAL_SERVER_ERROR']]],
        ));

        $payload = new GraphqlEndpointPayload();
        $payload->setQuery('subscription { articleChanges { id } }');

        $frame = $this->invokeInitialFrame($streamer, $payload);

        self::assertSame('next', $frame['_sse_event']);
        self::assertNull($frame['data']);
        self::assertSame('INTERNAL_SERVER_ERROR', $frame['errors'][0]['extensions']['code']);
    }

    public function test_initial_frame_passes_extensions_through_only_when_present(): void
    {
        $without = $this->invokeInitialFrame(
            $this->streamerWithExecutor(new GraphqlExecutionResult(data: ['x' => 1], errors: [])),
            $this->subscriptionPayload(),
        );
        self::assertArrayNotHasKey('extensions', $without);

        $with = $this->invokeInitialFrame(
            $this->streamerWithExecutor(new GraphqlExecutionResult(
                data: ['x' => 1],
                errors: [],
                extensions: ['tracing' => ['version' => 1]],
            )),
            $this->subscriptionPayload(),
        );
        self::assertSame(['tracing' => ['version' => 1]], $with['extensions']);
    }

    public function test_initial_frame_forwards_query_variables_and_operation_name_to_executor(): void
    {
        $executor = $this->recordingExecutor(new GraphqlExecutionResult(data: ['x' => 1], errors: []));
        $streamer = $this->bindExecutor($executor);

        $payload = new GraphqlEndpointPayload();
        $payload->setQuery('subscription Named { articleChanges { id } }');
        $payload->setVariables(['k' => 'v']);
        $payload->setOperationName('Named');

        $this->invokeInitialFrame($streamer, $payload);

        self::assertSame('subscription Named { articleChanges { id } }', $executor->lastQuery);
        self::assertSame(['k' => 'v'], $executor->lastVariables);
        self::assertSame('Named', $executor->lastOperationName);
    }

    // ---- PHASE 4: re-run tick + watchScopes consumption ---------------------

    public function test_rerun_tick_returns_one_shot_next_frame_body_and_never_touches_the_socket(): void
    {
        $streamer = $this->streamerWithExecutor(new GraphqlExecutionResult(
            data: ['articleChanges' => [['id' => 'a1']]],
            errors: [],
        ));
        (new ReflectionProperty($streamer, 'operationType'))->setValue($streamer, new GraphqlOperationTypeResolver());

        $resource = new GraphqlEndpointResource();
        $this->beginReRunScope();
        try {
            $served = $streamer->tryServe($this->subscriptionPayload(), $resource);
        } finally {
            $this->endReRunScope();
        }

        // Non-null: the re-run pipeline renders THIS body as the fresh frame.
        self::assertNotNull($served);
        $body = $served->getRenderContext();
        self::assertSame('next', $body['_sse_event']);
        self::assertSame(['articleChanges' => [['id' => 'a1']]], $body['data']);
        self::assertSame([], $body['errors']);
        // Re-run body rides the NORMAL renderer — never marked already-sent
        // (already-sent would mean the socket was grabbed: forbidden on a tick).
        self::assertFalse($served->isAlreadySent());
        self::assertSame(200, $served->getStatusCode());
    }

    public function test_rerun_tick_with_non_subscription_document_falls_through_to_one_shot(): void
    {
        $streamer = new GraphqlSseSubscriptionStreamer();
        (new ReflectionProperty($streamer, 'operationType'))->setValue($streamer, new GraphqlOperationTypeResolver());

        $payload = new GraphqlEndpointPayload();
        $payload->setQuery('query { articles { id } }');

        $this->beginReRunScope();
        try {
            self::assertNull($streamer->tryServe($payload, new GraphqlEndpointResource()));
        } finally {
            $this->endReRunScope();
        }
    }

    public function test_watch_scopes_union_from_matched_subscription_operations(): void
    {
        $streamer = $this->streamerWithRegistry([
            'articleChanges' => ['playground_articles', 'shared_scope'],
            'pingChanges' => ['shared_scope', 'ui_playground_leads'],
        ]);

        $payload = new GraphqlEndpointPayload();
        $payload->setQuery('subscription { articleChanges { id } pingChanges { id } }');

        self::assertSame(
            ['playground_articles', 'shared_scope', 'ui_playground_leads'],
            $this->invokeResolveWatchScopes($streamer, $payload),
        );
    }

    public function test_watch_scopes_empty_for_unknown_field_no_default_invented(): void
    {
        $streamer = $this->streamerWithRegistry([]);

        $payload = new GraphqlEndpointPayload();
        $payload->setQuery('subscription { somethingUnregistered { id } }');

        self::assertSame([], $this->invokeResolveWatchScopes($streamer, $payload));
    }

    public function test_watch_scopes_empty_when_operation_declares_none(): void
    {
        $streamer = $this->streamerWithRegistry(['articleChanges' => []]);

        $payload = new GraphqlEndpointPayload();
        $payload->setQuery('subscription { articleChanges { id } }');

        self::assertSame([], $this->invokeResolveWatchScopes($streamer, $payload));
    }

    private function beginReRunScope(): void
    {
        $m = new ReflectionMethod(AsyncResourceSseServer::class, 'beginReRunScope');
        $m->setAccessible(true);
        $m->invoke(null);
    }

    private function endReRunScope(): void
    {
        $m = new ReflectionMethod(AsyncResourceSseServer::class, 'endReRunScope');
        $m->setAccessible(true);
        $m->invoke(null);
    }

    /** @param array<string, list<string>> $subscriptionScopesByField */
    private function streamerWithRegistry(array $subscriptionScopesByField): GraphqlSseSubscriptionStreamer
    {
        $streamer = new GraphqlSseSubscriptionStreamer();
        (new ReflectionProperty($streamer, 'operationType'))->setValue($streamer, new GraphqlOperationTypeResolver());
        (new ReflectionProperty($streamer, 'operations'))->setValue(
            $streamer,
            new class($subscriptionScopesByField) implements GraphqlOperationRegistryInterface {
                /** @param array<string, list<string>> $scopesByField */
                public function __construct(private array $scopesByField) {}

                public function all(): array
                {
                    return [];
                }

                public function queries(): array
                {
                    return [];
                }

                public function mutations(): array
                {
                    return [];
                }

                public function subscriptions(): array
                {
                    return [];
                }

                public function find(string $rootType, string $field): ?ResolvedGraphqlOperation
                {
                    if ($rootType !== 'subscription' || !array_key_exists($field, $this->scopesByField)) {
                        return null;
                    }

                    return new ResolvedGraphqlOperation(
                        field: $field,
                        rootType: 'subscription',
                        payloadClass: 'Stub\\Payload',
                        outputClass: null,
                        routeName: 'stub.route',
                        path: '/stub',
                        httpMethods: ['GET'],
                        handlerClasses: [],
                        responseClass: 'Stub\\Resource',
                        description: '',
                        list: true,
                        watchScopes: $this->scopesByField[$field],
                    );
                }
            },
        );

        return $streamer;
    }

    /** @return list<string> */
    private function invokeResolveWatchScopes(
        GraphqlSseSubscriptionStreamer $streamer,
        GraphqlEndpointPayload $payload,
    ): array {
        $method = new ReflectionMethod($streamer, 'resolveWatchScopes');
        $method->setAccessible(true);
        /** @var list<string> $scopes */
        $scopes = $method->invoke($streamer, $payload);
        return $scopes;
    }

    // ---- PHASE 3: admission-gate pieces ------------------------------------

    public function test_refuse_disabled_is_http_400_with_transport_body_not_graphql_envelope(): void
    {
        $resource = new GraphqlEndpointResource();
        $refused = $this->invokeRefuse(new GraphqlSseSubscriptionStreamer(), $resource, GraphqlSseMode::Disabled);

        self::assertSame(400, $refused->getStatusCode());
        $body = $refused->getRenderContext();
        self::assertSame('SSE_DISABLED', $body['code']);
        self::assertArrayHasKey('error', $body);
        // Transport-level body, NOT a GraphQL ExecutionResult envelope.
        self::assertArrayNotHasKey('data', $body);
        self::assertArrayNotHasKey('errors', $body);
        // Refusal goes through the normal emitter — never marked already-sent.
        self::assertFalse($refused->isAlreadySent());
    }

    public function test_refuse_guest_in_authenticated_only_is_http_403_with_distinct_code(): void
    {
        $resource = new GraphqlEndpointResource();
        $refused = $this->invokeRefuse(new GraphqlSseSubscriptionStreamer(), $resource, GraphqlSseMode::AuthenticatedOnly);

        self::assertSame(403, $refused->getStatusCode());
        $body = $refused->getRenderContext();
        self::assertSame('SSE_FORBIDDEN', $body['code']);
        self::assertArrayHasKey('error', $body);
        self::assertArrayNotHasKey('data', $body);
        self::assertArrayNotHasKey('errors', $body);
        self::assertFalse($refused->isAlreadySent());
    }

    public function test_is_authenticated_fails_closed_without_an_auth_context_binding(): void
    {
        // No AuthContextInterface bound (the nullable best-effort injection
        // stayed null) → guest. authenticated-only must refuse, not crash open.
        self::assertFalse($this->invokeIsAuthenticated(new GraphqlSseSubscriptionStreamer()));
    }

    public function test_is_authenticated_reads_the_request_auth_context(): void
    {
        $streamer = new GraphqlSseSubscriptionStreamer();
        (new ReflectionProperty($streamer, 'authContext'))->setValue($streamer, $this->authContext(guest: true));
        self::assertFalse($this->invokeIsAuthenticated($streamer));

        $streamer = new GraphqlSseSubscriptionStreamer();
        (new ReflectionProperty($streamer, 'authContext'))->setValue($streamer, $this->authContext(guest: false));
        self::assertTrue($this->invokeIsAuthenticated($streamer));
    }

    private function invokeRefuse(
        GraphqlSseSubscriptionStreamer $streamer,
        GraphqlEndpointResource $resource,
        GraphqlSseMode $mode,
    ): GraphqlEndpointResource {
        $method = new ReflectionMethod($streamer, 'refuse');
        $method->setAccessible(true);
        /** @var GraphqlEndpointResource $refused */
        $refused = $method->invoke($streamer, $resource, $mode);
        return $refused;
    }

    private function invokeIsAuthenticated(GraphqlSseSubscriptionStreamer $streamer): bool
    {
        $method = new ReflectionMethod($streamer, 'isAuthenticated');
        $method->setAccessible(true);
        return (bool) $method->invoke($streamer);
    }

    private function authContext(bool $guest): AuthContextInterface
    {
        return new class($guest) implements AuthContextInterface {
            private static ?AuthContextInterface $unused = null;

            public function __construct(private bool $guest) {}

            public function getUser(): ?AuthenticatableInterface
            {
                return null;
            }

            public function isGuest(): bool
            {
                return $this->guest;
            }

            public function setUser(?AuthenticatableInterface $user): void
            {
            }

            public static function get(): ?AuthContextInterface
            {
                return self::$unused;
            }

            public static function getOrFail(): AuthContextInterface
            {
                throw new \LogicException('Not used in this stub.');
            }
        };
    }

    private function subscriptionPayload(): GraphqlEndpointPayload
    {
        $payload = new GraphqlEndpointPayload();
        $payload->setQuery('subscription { articleChanges { id } }');
        return $payload;
    }

    private function streamerWithExecutor(GraphqlExecutionResult $returns): GraphqlSseSubscriptionStreamer
    {
        return $this->bindExecutor($this->recordingExecutor($returns));
    }

    private function bindExecutor(GraphqlExecutorInterface $executor): GraphqlSseSubscriptionStreamer
    {
        $streamer = new GraphqlSseSubscriptionStreamer();
        (new ReflectionProperty($streamer, 'executor'))->setValue($streamer, $executor);
        return $streamer;
    }

    /** @return array<string, mixed> */
    private function invokeInitialFrame(
        GraphqlSseSubscriptionStreamer $streamer,
        GraphqlEndpointPayload $payload,
    ): array {
        $method = new ReflectionMethod($streamer, 'nextFrame');
        $method->setAccessible(true);
        /** @var array<string, mixed> $frame */
        $frame = $method->invoke($streamer, $payload);
        return $frame;
    }

    private function recordingExecutor(GraphqlExecutionResult $returns): object
    {
        return new class($returns) implements GraphqlExecutorInterface {
            public string $lastQuery = '';
            /** @var array<string, mixed>|null */
            public ?array $lastVariables = null;
            public ?string $lastOperationName = null;

            public function __construct(private GraphqlExecutionResult $returns) {}

            public function execute(string $query, ?array $variables = null, ?string $operationName = null): GraphqlExecutionResult
            {
                $this->lastQuery = $query;
                $this->lastVariables = $variables;
                $this->lastOperationName = $operationName;
                return $this->returns;
            }
        };
    }
}
