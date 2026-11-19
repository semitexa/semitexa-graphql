<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Api\Auth\MachinePrincipal;
use Semitexa\Api\Domain\Model\MachineCredential;
use Semitexa\Core\Auth\AuthenticatableInterface;
use Semitexa\Core\Auth\AuthResult;
use Semitexa\Core\Exception\AccessDeniedException;
use Semitexa\Core\Exception\RateLimitException;
use Semitexa\Core\Lifecycle\CurrentRequestStore;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Core\Request;
use Semitexa\Graphql\Application\Service\Runtime\GraphqlOperationGate;
use Semitexa\Graphql\Domain\Model\GraphqlExecutionResult;
use Semitexa\Graphql\Tests\Fixture\Access\AccessCapabilityFixture;
use Semitexa\Graphql\Tests\Fixture\Access\AccessHandlerFixture;
use Semitexa\Graphql\Tests\Fixture\Access\PermissionPayloadFixture;
use Semitexa\Graphql\Tests\Support\AccessHarness;
use Semitexa\Graphql\Tests\Support\RecordingLogger;

/**
 * `POST /graphql` is public, and an operation is another route's payload whose
 * handler was called directly: nothing that guards that payload over HTTP ran.
 * A guest could run a `#[AsProtectedPayload]` operation, and any caller one
 * that needs a permission, a capability or a scope.
 *
 * Each case runs a real document through the real executor, with the access
 * components the HTTP pipeline uses. A refusal is proved twice: by the typed
 * error, and by the handler never having been called.
 */
final class GraphqlOperationAccessTest extends TestCase
{
    /** @var array<mixed> what other tests left in the CLI store */
    private array $saved = [];

    private AccessHarness $harness;

    private RecordingLogger $log;

    protected function setUp(): void
    {
        $this->saved = AccessHarness::takeCliStore();
        AccessHandlerFixture::$calls = 0;

        $this->harness = new AccessHarness();
        StaticLoggerBridge::set($this->log = new RecordingLogger());
        CurrentRequestStore::set(new Request('POST', '/graphql', [], [], [], [], [], '{}'));
    }

    protected function tearDown(): void
    {
        StaticLoggerBridge::reset();
        AccessHarness::restoreCliStore($this->saved);
    }

    // ---- access type ---------------------------------------------------------

    public function test_a_guest_is_refused_a_protected_operation(): void
    {
        $result = $this->query('{ protectedThing(id: "7") { id name } }');

        $this->assertRefused($result, 'UNAUTHENTICATED', 'protectedThing');
    }

    public function test_a_signed_in_user_runs_a_protected_operation(): void
    {
        $this->signInAsUser();

        $result = $this->query('{ protectedThing(id: "7") { id name } }');

        self::assertSame([], $result->errors);
        self::assertSame(['protectedThing' => ['id' => '7', 'name' => 'secret-7']], $result->data);
        self::assertSame(1, AccessHandlerFixture::$calls);
    }

    /** The guest is refused before a setter sees an argument: no validation answer to probe with. */
    public function test_a_guest_is_refused_before_the_arguments_are_hydrated(): void
    {
        $result = $this->query('{ protectedThing(id: "") { id } }');

        $this->assertRefused($result, 'UNAUTHENTICATED', 'protectedThing');
    }

    public function test_a_guest_is_refused_a_service_operation(): void
    {
        $result = $this->query('{ scopeThing(id: "7") { id } }');

        $this->assertRefused($result, 'UNAUTHENTICATED', 'scopeThing');
    }

    // ---- permission, capability ---------------------------------------------

    public function test_a_user_without_the_permission_is_refused(): void
    {
        $this->signInAsUser();
        $this->harness->grantedPermissions = ['reports.write'];

        $result = $this->query('{ permissionThing(id: "7") { id name } }');

        $this->assertRefused($result, 'FORBIDDEN', 'permissionThing');
        self::assertStringContainsString('reports.read', AccessHarness::error($result->errors[0])['message']);
    }

    public function test_a_user_with_the_permission_runs_the_operation(): void
    {
        $this->signInAsUser();
        $this->harness->grantedPermissions = ['reports.read'];

        $result = $this->query('{ permissionThing(id: "7") { id } }');

        self::assertSame([], $result->errors);
        self::assertSame(['permissionThing' => ['id' => '7']], $result->data);
    }

    public function test_a_guest_is_refused_a_permission_operation_as_unauthenticated(): void
    {
        $this->harness->grantedPermissions = ['reports.read'];

        $this->assertRefused($this->query('{ permissionThing(id: "7") { id } }'), 'UNAUTHENTICATED', 'permissionThing');
    }

    public function test_a_user_without_the_capability_is_refused(): void
    {
        $this->signInAsUser();

        $result = $this->query('{ capabilityThing(id: "7") { id name } }');

        $this->assertRefused($result, 'FORBIDDEN', 'capabilityThing');
    }

    public function test_a_user_with_the_capability_runs_the_operation(): void
    {
        $this->signInAsUser();
        $this->harness->grantedCapabilities = [AccessCapabilityFixture::Audit];

        $result = $this->query('{ capabilityThing(id: "7") { id } }');

        self::assertSame([], $result->errors);
        self::assertSame(['capabilityThing' => ['id' => '7']], $result->data);
    }

    // ---- scope ---------------------------------------------------------------

    public function test_a_machine_credential_without_the_scope_is_refused(): void
    {
        $this->signInAsMachine(['orders.write']);

        $result = $this->query('{ scopeThing(id: "7") { id name } }');

        $this->assertRefused($result, 'FORBIDDEN', 'scopeThing');
        self::assertStringContainsString('orders.read', AccessHarness::error($result->errors[0])['message']);
    }

    public function test_a_machine_credential_with_the_scope_runs_the_operation(): void
    {
        $this->signInAsMachine(['orders.read']);

        $result = $this->query('{ scopeThing(id: "7") { id } }');

        self::assertSame([], $result->errors);
        self::assertSame(['scopeThing' => ['id' => '7']], $result->data);
    }

    /** A user session carries no scopes, and is the wrong kind of subject for a service payload. */
    public function test_a_user_session_is_refused_a_scope_operation(): void
    {
        $this->signInAsUser();

        $this->assertRefused($this->query('{ scopeThing(id: "7") { id } }'), 'UNAUTHENTICATED', 'scopeThing');
    }

    // ---- requirements nothing can evaluate ----------------------------------

    public function test_a_signed_url_operation_is_refused_to_everyone(): void
    {
        $this->assertRefused($this->query('{ signedThing(id: "7") { id } }'), 'FORBIDDEN', 'signedThing');

        $this->signInAsUser();
        $result = $this->query('{ signedThing(id: "7") { id } }');
        $this->assertRefused($result, 'FORBIDDEN', 'signedThing');
        $this->assertReasonOnlyInTheLog($result, 'RequiresSignedUrl');
    }

    public function test_an_unknown_requirement_is_refused_not_skipped(): void
    {
        $this->signInAsUser();

        $result = $this->query('{ moonThing(id: "7") { id } }');

        $this->assertRefused($result, 'FORBIDDEN', 'moonThing');
        $this->assertReasonOnlyInTheLog($result, 'RequiresMoonPhaseFixture');
    }

    public function test_a_payload_that_declares_no_access_type_never_runs(): void
    {
        $this->signInAsUser();

        $result = $this->query('{ undeclaredThing(id: "7") { id } }');

        self::assertSame(['undeclaredThing' => null], $result->data);
        self::assertCount(1, $result->errors);
        self::assertSame(0, AccessHandlerFixture::$calls);
    }

    public function test_without_the_authorization_package_every_non_public_operation_is_refused(): void
    {
        $this->harness->accessPackagesInstalled = false;
        $this->signInAsUser();

        foreach (['protectedThing', 'permissionThing', 'capabilityThing', 'scopeThing', 'undeclaredThing'] as $field) {
            $result = $this->query(sprintf('{ %s(id: "7") { id } }', $field));
            $this->assertRefused($result, 'FORBIDDEN', $field);
            $this->assertReasonOnlyInTheLog($result, 'is refused');
        }
    }

    public function test_without_an_http_request_a_non_public_operation_is_refused(): void
    {
        CurrentRequestStore::clear();
        $this->signInAsUser();

        $result = $this->query('{ protectedThing(id: "7") { id } }');
        $this->assertRefused($result, 'UNAUTHENTICATED', 'protectedThing');
        self::assertSame('Authentication required.', AccessHarness::error($result->errors[0])['message']);
    }

    // ---- validation ----------------------------------------------------------

    public function test_a_payload_its_validate_refuses_gives_VALIDATION_FAILED(): void
    {
        $result = $this->query('{ rangeThing(from: 9, to: 3) { id } }');

        $this->assertRefused($result, 'VALIDATION_FAILED', 'rangeThing');
        self::assertSame(['to' => ['to must not be before from']], AccessHarness::error($result->errors[0])['extensions']['errors'] ?? null);
    }

    public function test_a_payload_its_validate_accepts_runs(): void
    {
        $result = $this->query('{ rangeThing(from: 3, to: 9) { id } }');

        self::assertSame([], $result->errors);
        self::assertSame(['rangeThing' => ['id' => '3-9']], $result->data);
    }

    // ---- subscriptions: every SSE frame is this execution -------------------

    public function test_a_guest_is_refused_a_protected_subscription(): void
    {
        $result = $this->query('subscription { protectedThingChanges(id: "7") { id name } }');

        $this->assertRefused($result, 'UNAUTHENTICATED', 'protectedThingChanges');
    }

    public function test_a_user_without_the_permission_is_refused_a_subscription(): void
    {
        $this->signInAsUser();

        $result = $this->query('subscription { permissionThingChanges(id: "7") { id name } }');

        $this->assertRefused($result, 'FORBIDDEN', 'permissionThingChanges');
    }

    public function test_a_user_with_the_permission_runs_the_subscription(): void
    {
        $this->signInAsUser();
        $this->harness->grantedPermissions = ['reports.read'];

        $result = $this->query('subscription { permissionThingChanges(id: "7") { id } }');

        self::assertSame([], $result->errors);
        self::assertSame(['permissionThingChanges' => ['id' => '7']], $result->data);
    }

    // ---- public operations, and the rest of the AuthCheck phase -------------

    public function test_a_public_operation_still_runs_for_a_guest(): void
    {
        $result = $this->query('{ runtimeFixture(id: "abc", limit: 5) { id name count } }');

        self::assertSame([], $result->errors);
        self::assertSame(['runtimeFixture' => ['id' => 'abc', 'name' => 'name-abc', 'count' => 5]], $result->data);
    }

    public function test_a_public_operation_still_runs_without_the_authorization_package(): void
    {
        $this->harness->accessPackagesInstalled = false;

        $result = $this->query('{ runtimeFixture(id: "abc") { id } }');

        self::assertSame([], $result->errors);
        self::assertSame(['runtimeFixture' => ['id' => 'abc']], $result->data);
    }

    /** One refused field does not take the public one beside it down, nor the other way round. */
    public function test_a_refused_operation_does_not_refuse_the_public_one_beside_it(): void
    {
        $result = $this->query('{ runtimeFixture(id: "abc") { id } protectedThing(id: "7") { id } }');

        self::assertSame(['runtimeFixture' => ['id' => 'abc'], 'protectedThing' => null], $result->data);
        self::assertCount(1, $result->errors);
        self::assertSame('UNAUTHENTICATED', AccessHarness::error($result->errors[0])['code']);
        self::assertSame(0, AccessHandlerFixture::$calls);
    }

    /**
     * The gate runs the route's whole AuthCheck phase, not a list of access
     * rules of its own: a listener it has never heard of sees the operation's
     * payload, its route and this request, and its refusal stands.
     */
    public function test_every_auth_check_listener_sees_the_operation_and_can_refuse_it(): void
    {
        $listener = new class {
            /** @var list<array{string, ?string, string}> */
            public array $seen = [];
            public bool $refuse = false;

            public function handle(RequestPipelineContext $context): void
            {
                $this->seen[] = [$context->requestDto::class, $context->route->name, $context->request->getPath()];
                if ($this->refuse) {
                    throw new RateLimitException(30);
                }
            }
        };
        $this->harness->extraListeners = [$listener::class => $listener];
        $this->signInAsUser();
        $this->harness->grantedPermissions = ['reports.read'];

        $this->query('{ permissionThing(id: "7") { id } }');
        self::assertSame([[PermissionPayloadFixture::class, 'access.permission', '/graphql']], $listener->seen);
        self::assertSame(1, AccessHandlerFixture::$calls);

        AccessHandlerFixture::$calls = 0;
        $listener->refuse = true;
        $this->assertRefused($this->query('{ runtimeFixture(id: "abc") { id } }'), 'RATE_LIMITED', 'runtimeFixture');
    }

    /** The listeners judge the payload they are handed: it must be the operation's own. */
    public function test_a_payload_of_another_class_is_never_admitted(): void
    {
        $operation = $this->harness->operations->find('query', 'runtimeFixture');
        self::assertNotNull($operation);

        $this->expectException(AccessDeniedException::class);
        $this->harness->gate()->admit($operation, static fn (): object => new \stdClass());
    }

    // ---- helpers -------------------------------------------------------------

    private function query(string $document): GraphqlExecutionResult
    {
        return $this->harness->executor()->execute($document);
    }

    private function assertRefused(GraphqlExecutionResult $result, string $code, string $field): void
    {
        self::assertCount(1, $result->errors, 'one error for the refused field');
        $error = AccessHarness::error($result->errors[0]);
        self::assertSame($code, $error['code'], $error['message']);
        self::assertSame([$field], $result->errors[0]['path'] ?? null);
        self::assertSame([$field => null], $result->data);
        self::assertSame(0, AccessHandlerFixture::$calls, 'the handler of a refused operation must not run');
    }

    /**
     * A refusal decided by the gate names payload and listener classes in its
     * reason, and the caller may be anonymous: the response carries the code
     * and one fixed sentence, the reason is in the log.
     */
    private function assertReasonOnlyInTheLog(GraphqlExecutionResult $result, string $reason): void
    {
        $body = json_encode($result->toArray(), JSON_THROW_ON_ERROR);

        self::assertSame(GraphqlOperationGate::UNAVAILABLE, AccessHarness::error($result->errors[0])['message']);
        // The body is the refusal, whole: what follows is absent from it, not from nothing.
        self::assertStringContainsString(GraphqlOperationGate::UNAVAILABLE, $body);
        self::assertStringNotContainsString($reason, $body);
        self::assertDoesNotMatchRegularExpression('/Semitexa|Fixture|Listener|#\\[/', $body);
        self::assertStringContainsString($reason, implode("\n", $this->log->messages));
        self::assertStringContainsString('Semitexa\\', implode("\n", $this->log->messages));
    }

    private function signInAsUser(): void
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

    /** @param list<string> $scopes */
    private function signInAsMachine(array $scopes): void
    {
        $this->harness->authenticatesAs = AuthResult::successAsService(
            new MachinePrincipal(new MachineCredential('cred-1', 'fixture-client', 'hash', $scopes)),
        );
    }
}
