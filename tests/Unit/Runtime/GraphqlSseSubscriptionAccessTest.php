<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Semitexa\Core\Auth\AuthenticatableInterface;
use Semitexa\Core\Auth\AuthResult;
use Semitexa\Core\Exception\AccessDeniedException;
use Semitexa\Core\Exception\AuthenticationException;
use Semitexa\Core\Lifecycle\CurrentRequestStore;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Core\Request;
use Semitexa\Graphql\Application\Payload\Request\GraphqlEndpointPayload;
use Semitexa\Graphql\Application\Resource\Response\GraphqlEndpointResource;
use Semitexa\Graphql\Application\Service\Runtime\GraphqlOperationGate;
use Semitexa\Graphql\Application\Service\Runtime\GraphqlOperationTypeResolver;
use Semitexa\Graphql\Application\Service\Runtime\GraphqlSseSubscriptionStreamer;
use Semitexa\Graphql\Application\Service\Runtime\GraphqlSubscriptionAccess;
use Semitexa\Graphql\Tests\Fixture\Access\AccessHandlerFixture;
use Semitexa\Graphql\Tests\Support\AccessHarness;
use Semitexa\Ssr\Application\Service\Async\AsyncResourceSseServer;
use Semitexa\Ssr\Application\Service\Async\SseServer;

/**
 * A subscription over SSE runs the operation's handler on connect and again on
 * every invalidation tick. The stream's own mode gate asks only whether the
 * FEATURE admits the caller; the operation's access policy was asked nowhere.
 *
 * Three places now ask it, all through the one operation gate: before the
 * stream opens (a clean 401/403, no stream), on every tick (the stream is
 * closed), and inside every frame's execution (the handler is not invoked).
 */
final class GraphqlSseSubscriptionAccessTest extends TestCase
{
    /** @var array<mixed> what other tests left in the CLI store */
    private array $saved = [];

    private AccessHarness $harness;

    private int $savedCalls = 0;

    protected function setUp(): void
    {
        $this->saved = AccessHarness::takeCliStore();
        $this->savedCalls = AccessHandlerFixture::$calls;
        AccessHandlerFixture::$calls = 0;

        $this->harness = new AccessHarness();
        CurrentRequestStore::set(new Request('POST', '/graphql', ['accept' => 'text/event-stream'], [], [], [], [], '{}'));
    }

    protected function tearDown(): void
    {
        AccessHandlerFixture::$calls = $this->savedCalls;
        AccessHarness::restoreCliStore($this->saved);
    }

    public function test_a_guest_is_refused_a_protected_subscription_before_the_stream_opens(): void
    {
        $refusal = $this->refusal('subscription { protectedThingChanges(id: "7") { id } }');

        self::assertInstanceOf(AuthenticationException::class, $refusal);

        $resource = $this->refuse($refusal);
        self::assertSame(401, $resource->getStatusCode());
        self::assertSame('SSE_OPERATION_UNAUTHENTICATED', $resource->getRenderContext()['code']);
        // A transport refusal, sent by the normal emitter: no stream was opened.
        self::assertFalse($resource->isAlreadySent());
        self::assertArrayNotHasKey('data', $resource->getRenderContext());
        self::assertSame(0, AccessHandlerFixture::$calls);
    }

    public function test_a_subscription_that_cannot_be_enforced_is_refused_as_forbidden(): void
    {
        $this->harness->accessPackagesInstalled = false;
        $this->signIn();
        $refusal = $this->refusal('subscription { protectedThingChanges(id: "7") { id } }');
        self::assertInstanceOf(AccessDeniedException::class, $refusal);

        $resource = $this->refuse($refusal);
        self::assertSame(403, $resource->getStatusCode());
        self::assertSame('SSE_OPERATION_FORBIDDEN', $resource->getRenderContext()['code']);
        // The reason names classes; an anonymous caller is told only this.
        self::assertSame(GraphqlOperationGate::UNAVAILABLE, $resource->getRenderContext()['error']);
        $body = json_encode($resource->getRenderContext(), JSON_THROW_ON_ERROR);
        self::assertStringContainsString('SSE_OPERATION_FORBIDDEN', $body);
        self::assertStringNotContainsString('Semitexa', $body);
    }

    /**
     * The executor expands a fragment at the subscription root and runs its
     * fields; the root field names, read off the document, did not include
     * them, so a guest was checked against no operation and admitted.
     *
     * @return iterable<string, array{string}>
     */
    public static function fragmentRootedSubscriptions(): iterable
    {
        yield 'fragment spread' => [
            'subscription { ...F } fragment F on Subscription { protectedThingChanges(id: "7") { id } }',
        ];
        yield 'inline fragment' => [
            'subscription { ... on Subscription { protectedThingChanges(id: "7") { id } } }',
        ];
        yield 'untyped inline fragment' => [
            'subscription { ... { protectedThingChanges(id: "7") { id } } }',
        ];
    }

    #[DataProvider('fragmentRootedSubscriptions')]
    public function test_a_subscription_rooted_in_a_fragment_opens_no_stream(string $document): void
    {
        $refusal = $this->refusal($document);

        self::assertInstanceOf(AccessDeniedException::class, $refusal);
        self::assertSame(GraphqlSubscriptionAccess::FRAGMENT_AT_ROOT, $refusal->getMessage());
        self::assertSame(403, $this->refuse($refusal)->getStatusCode());

        // Not for a guest only: no stream reads its operations off such a root.
        $this->signIn();
        self::assertInstanceOf(AccessDeniedException::class, $this->refusal($document));
    }

    #[DataProvider('fragmentRootedSubscriptions')]
    public function test_a_tick_closes_a_stream_rooted_in_a_fragment(string $document): void
    {
        $streamer = $this->streamer();

        $this->beginReRunScope();
        try {
            $streamer->tryServe($this->payload($document), new GraphqlEndpointResource());
            self::fail('a tick must not execute a subscription rooted in a fragment');
        } catch (AccessDeniedException) {
            self::assertSame(0, AccessHandlerFixture::$calls);
        } finally {
            $this->endReRunScope();
        }
    }

    public function test_a_signed_in_user_and_a_public_subscription_are_not_refused(): void
    {
        self::assertNull($this->refusal('subscription { runtimeFixtureChanges(id: "7") { id } }'));

        $this->signIn();
        self::assertNull($this->refusal('subscription { protectedThingChanges(id: "7") { id } }'));
    }

    /** Who signed out since the stream opened: the tick closes it, and runs nothing. */
    public function test_a_tick_closes_the_stream_of_a_caller_who_is_no_longer_signed_in(): void
    {
        $streamer = $this->streamer();

        $this->beginReRunScope();
        try {
            $streamer->tryServe($this->payload('subscription { protectedThingChanges(id: "7") { id } }'), new GraphqlEndpointResource());
            self::fail('a tick for a signed-out caller must terminate the stream');
        } catch (AuthenticationException) {
            // RouteExecutor::reExecute() turns this into ReRunResult::terminate().
            self::assertSame(0, AccessHandlerFixture::$calls);
        } finally {
            $this->endReRunScope();
        }
    }

    public function test_a_tick_still_emits_for_a_caller_who_may_run_the_subscription(): void
    {
        $this->signIn();
        $streamer = $this->streamer();

        $this->beginReRunScope();
        try {
            $served = $streamer->tryServe($this->payload('subscription { protectedThingChanges(id: "7") { id } }'), new GraphqlEndpointResource());
        } finally {
            $this->endReRunScope();
        }

        self::assertNotNull($served);
        self::assertSame(['protectedThingChanges' => ['id' => '7']], $served->getRenderContext()['data']);
        self::assertSame(1, AccessHandlerFixture::$calls);
    }

    /** A permission needs the hydrated payload, so it is decided in the frame: an error, and no handler. */
    public function test_a_frame_for_a_user_without_the_permission_carries_FORBIDDEN_and_no_data(): void
    {
        $this->signIn();
        $streamer = $this->streamer();

        $method = new ReflectionMethod($streamer, 'nextFrame');
        /** @var array<string, mixed> $frame */
        $frame = $method->invoke($streamer, $this->payload('subscription { permissionThingChanges(id: "7") { id name } }'));

        self::assertSame('next', $frame['_sse_event']);
        self::assertSame(['permissionThingChanges' => null], $frame['data']);
        $errors = $frame['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertSame('FORBIDDEN', AccessHarness::error($errors[0] ?? null)['code']);
        self::assertSame(0, AccessHandlerFixture::$calls);
    }

    /**
     * A tick is the request already counted. The operation's listeners learn
     * it the way the route's own re-run tells them: by the context's flag.
     */
    public function test_a_tick_runs_the_operations_listeners_as_a_re_run(): void
    {
        $listener = new class {
            /** @var list<bool> */
            public array $reRuns = [];

            public function handle(RequestPipelineContext $context): void
            {
                $this->reRuns[] = $context->reRun;
            }
        };
        $this->harness->extraListeners = [$listener::class => $listener];
        $this->signIn();
        $streamer = $this->streamer();
        $payload = $this->payload('subscription { protectedThingChanges(id: "7") { id } }');

        (new ReflectionMethod($streamer, 'nextFrame'))->invoke($streamer, $payload);
        $this->beginReRunScope();
        try {
            $streamer->tryServe($payload, new GraphqlEndpointResource());
        } finally {
            $this->endReRunScope();
        }

        self::assertSame([false, true], $listener->reRuns);
    }

    private function streamer(): GraphqlSseSubscriptionStreamer
    {
        $streamer = new GraphqlSseSubscriptionStreamer();
        foreach ([
            'operationType' => new GraphqlOperationTypeResolver(),
            'executor' => $this->harness->executor(),
            'operations' => $this->harness->operations,
            'subscriptionAccess' => $this->access(),
            'sseServer' => AsyncResourceSseServer::instance(),
        ] as $property => $value) {
            (new ReflectionProperty($streamer, $property))->setValue($streamer, $value);
        }

        return $streamer;
    }

    private function access(): GraphqlSubscriptionAccess
    {
        $access = new GraphqlSubscriptionAccess();
        $access->setCollaboratorsForTest(new GraphqlOperationTypeResolver(), $this->harness->operations, $this->harness->gate());

        return $access;
    }

    private function payload(string $document): GraphqlEndpointPayload
    {
        $payload = new GraphqlEndpointPayload();
        $payload->setQuery($document);

        return $payload;
    }

    private function refusal(string $document): AuthenticationException|AccessDeniedException|null
    {
        return $this->access()->refusal($this->payload($document));
    }

    private function refuse(AuthenticationException|AccessDeniedException $refusal): GraphqlEndpointResource
    {
        return $this->access()->refuse(new GraphqlEndpointResource(), $refusal);
    }

    private function signIn(): void
    {
        $this->harness->authenticatesAs = AuthResult::successAsUser(new class implements AuthenticatableInterface {
            public function getId(): string
            {
                return 'user-1';
            }

            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthIdentifier(): mixed
            {
                return 'user-1';
            }
        });
    }

    private function beginReRunScope(): void
    {
        (new ReflectionMethod(SseServer::class, 'beginReRunScope'))->invoke(AsyncResourceSseServer::instance());
    }

    private function endReRunScope(): void
    {
        (new ReflectionMethod(SseServer::class, 'endReRunScope'))->invoke(AsyncResourceSseServer::instance());
    }
}
