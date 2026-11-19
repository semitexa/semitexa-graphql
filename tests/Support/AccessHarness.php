<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Support;

use PHPUnit\Framework\Assert;
use ReflectionClass;
use ReflectionProperty;
use Semitexa\Authorization\Application\Service\Authorizer;
use Semitexa\Authorization\Domain\Contract\CapabilityInterface;
use Semitexa\Authorization\Domain\Contract\SubjectGrantResolverInterface;
use Semitexa\Authorization\Domain\Model\CapabilityGrantSet;
use Semitexa\Authorization\Domain\Model\PermissionGrantSet;
use Semitexa\Authorization\Domain\Model\SubjectGrantSet;
use Semitexa\Authorization\Pipeline\AuthorizationListener;
use Semitexa\Authorization\Pipeline\PreHydrationAuthGate;
use Semitexa\Core\Auth\AuthBootstrapperFactoryInterface;
use Semitexa\Core\Auth\AuthBootstrapperInterface;
use Semitexa\Core\Auth\AuthenticationMode;
use Semitexa\Core\Auth\AuthResult;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Authorization\SubjectInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\Pipeline\AuthCheck;
use Semitexa\Core\Pipeline\PipelineListenerRegistry;
use Semitexa\Core\Pipeline\PreHydrationAuthGateInterface;
use Semitexa\Graphql\Application\Service\Runtime\ContainerHandlerInvoker;
use Semitexa\Graphql\Application\Service\Runtime\GraphqlOperationGate;
use Semitexa\Graphql\Application\Service\Runtime\PayloadArgsHydrator;
use Semitexa\Graphql\Application\Service\Runtime\RenderContextResourceSerializer;
use Semitexa\Graphql\Application\Service\Runtime\WebonyxGraphqlExecutor;
use Semitexa\Graphql\Application\Service\Schema\OutputTypeRegistry;
use Semitexa\Graphql\Application\Service\Schema\PayloadArgumentBuilder;
use Semitexa\Graphql\Application\Service\Schema\ScalarTypeMapper;
use Semitexa\Graphql\Application\Service\Schema\SchemaBuilder;
use Semitexa\Graphql\Discovery\GraphqlOperationRegistry;
use Semitexa\Graphql\Pipeline\GraphqlErrorMapper;
use Semitexa\Graphql\Tests\Fixture\Access\AccessHandlerFixture;
use Semitexa\Graphql\Tests\Fixture\Access\CapabilityPayloadFixture;
use Semitexa\Graphql\Tests\Fixture\Access\PermissionPayloadFixture;
use Semitexa\Graphql\Tests\Fixture\Access\ProtectedPayloadFixture;
use Semitexa\Graphql\Tests\Fixture\Access\ScopePayloadFixture;
use Semitexa\Graphql\Tests\Fixture\Access\SignedUrlPayloadFixture;
use Semitexa\Graphql\Tests\Fixture\Access\UndeclaredAccessPayloadFixture;
use Semitexa\Graphql\Tests\Fixture\Access\UnknownRequirementPayloadFixture;
use Semitexa\Graphql\Tests\Fixture\Access\ValidatedPayloadFixture;
use Semitexa\Graphql\Tests\Fixture\Runtime\RuntimeHandlerFixture;
use Semitexa\Graphql\Tests\Fixture\Runtime\RuntimePayloadFixture;
use Semitexa\Graphql\Tests\Fixture\Runtime\RuntimeResourceFixture;
use Semitexa\Graphql\Tests\Integration\InMemoryContainer;

/**
 * The GraphQL runtime over the access fixtures, wired to the REAL access
 * components of the HTTP pipeline: core's PipelineExecutor and listener
 * registry, semitexa-authorization's pre-hydration gate, listener and
 * authorizer, semitexa-api's scope listener. Only the edges are doubles: who
 * the request authenticates as, and what that subject is granted.
 */
final class AccessHarness
{
    /** What the request's credentials resolve to; null is a guest. */
    public ?AuthResult $authenticatesAs = null;

    /** @var list<string> */
    public array $grantedPermissions = [];

    /** @var list<CapabilityInterface> */
    public array $grantedCapabilities = [];

    /** Extra AuthCheck listeners, by class, run after the access ones. */
    /** @var array<class-string, object> */
    public array $extraListeners = [];

    /** False: an application without semitexa-authorization and semitexa-api. */
    public bool $accessPackagesInstalled = true;

    public readonly GraphqlOperationRegistry $operations;

    public function __construct()
    {
        $routes = [];
        foreach (self::payloads() as $name => [$payloadClass, $handlerClass]) {
            $routes[] = StubRouteInspectionRegistry::metadata(
                payloadClass: $payloadClass,
                name: $name,
                path: '/' . str_replace('.', '/', $name),
                methods: ['GET'],
                responseClass: RuntimeResourceFixture::class,
                handlers: [['class' => $handlerClass]],
            );
        }

        $this->operations = new GraphqlOperationRegistry();
        (new ReflectionProperty($this->operations, 'routes'))->setValue($this->operations, new StubRouteInspectionRegistry($routes));
    }

    public function gate(): GraphqlOperationGate
    {
        $gate = new GraphqlOperationGate();
        $gate->setContainerForTest($this->container());

        return $gate;
    }

    public function executor(): WebonyxGraphqlExecutor
    {
        $scalars = new ScalarTypeMapper();
        $outputs = new OutputTypeRegistry();
        $outputs->setScalarsForTest($scalars);
        $arguments = new PayloadArgumentBuilder();
        $arguments->setScalarsForTest($scalars);

        $invoker = new ContainerHandlerInvoker();
        $invoker->setContainerForTest(new InMemoryContainer([
            AccessHandlerFixture::class => new AccessHandlerFixture(),
            RuntimeHandlerFixture::class => new RuntimeHandlerFixture(),
        ]));

        $schema = new SchemaBuilder();
        $schema->setCollaboratorsForTest(
            operations: $this->operations,
            outputTypes: $outputs,
            argumentBuilder: $arguments,
            payloadHydrator: new PayloadArgsHydrator(),
            handlerInvoker: $invoker,
            resourceSerializer: new RenderContextResourceSerializer(),
            operationGate: $this->gate(),
        );

        $executor = new WebonyxGraphqlExecutor();
        $executor->setCollaboratorsForTest($schema, new GraphqlErrorMapper());

        return $executor;
    }

    /**
     * One entry of a GraphQL `errors` list, narrowed: its message, its
     * `extensions.code`, and the rest of the extensions.
     *
     * @return array{message: string, code: string, extensions: array<mixed>}
     */
    public static function error(mixed $error): array
    {
        Assert::assertIsArray($error);
        $message = $error['message'] ?? null;
        $extensions = $error['extensions'] ?? null;
        Assert::assertIsString($message);
        Assert::assertIsArray($extensions);
        $code = $extensions['code'] ?? null;
        Assert::assertIsString($code);

        return ['message' => $message, 'code' => $code, 'extensions' => $extensions];
    }

    /**
     * What CoroutineLocal holds outside a coroutine. The gate and the request
     * store write there; a test takes it before and puts it back after, so
     * neither its leftovers nor its clean start reach another test.
     *
     * @return array<mixed>
     */
    public static function takeCliStore(): array
    {
        $saved = self::cliStore()->getValue();
        \Semitexa\Core\Support\CoroutineLocal::resetCliStore();

        return is_array($saved) ? $saved : [];
    }

    /** @param array<mixed> $saved */
    public static function restoreCliStore(array $saved): void
    {
        self::cliStore()->setValue(null, $saved);
    }

    private static function cliStore(): ReflectionProperty
    {
        return new ReflectionProperty(\Semitexa\Core\Support\CoroutineLocal::class, 'cliStore');
    }

    /** @return array<string, array{class-string, class-string}> route name => [payload, handler] */
    private static function payloads(): array
    {
        return [
            'runtime.fixture' => [RuntimePayloadFixture::class, RuntimeHandlerFixture::class],
            'access.protected' => [ProtectedPayloadFixture::class, AccessHandlerFixture::class],
            'access.permission' => [PermissionPayloadFixture::class, AccessHandlerFixture::class],
            'access.capability' => [CapabilityPayloadFixture::class, AccessHandlerFixture::class],
            'access.scope' => [ScopePayloadFixture::class, AccessHandlerFixture::class],
            'access.signed' => [SignedUrlPayloadFixture::class, AccessHandlerFixture::class],
            'access.moon' => [UnknownRequirementPayloadFixture::class, AccessHandlerFixture::class],
            'access.undeclared' => [UndeclaredAccessPayloadFixture::class, AccessHandlerFixture::class],
            'access.range' => [ValidatedPayloadFixture::class, AccessHandlerFixture::class],
        ];
    }

    private function container(): InMemoryContainer
    {
        $harness = $this;

        $bootstrapper = new class($harness) implements AuthBootstrapperInterface {
            public function __construct(private readonly AccessHarness $harness) {}

            public function isEnabled(): bool
            {
                return true;
            }

            public function handle(object $payload, AuthenticationMode $mode = AuthenticationMode::Mandatory): AuthResult
            {
                return $this->harness->authenticatesAs ?? AuthResult::failed('no credentials');
            }
        };

        $listeners = $this->extraListeners;
        $bindings = [
            RouteRegistry::class => $this->routeRegistry(),
            AuthBootstrapperFactoryInterface::class => new class($bootstrapper) implements AuthBootstrapperFactoryInterface {
                public function __construct(private readonly AuthBootstrapperInterface $bootstrapper) {}

                public function create(
                    \Psr\Container\ContainerInterface $container,
                    RequestScopedContainer $requestScopedContainer,
                ): AuthBootstrapperInterface {
                    return $this->bootstrapper;
                }
            },
        ];

        if ($this->accessPackagesInstalled) {
            $authorizer = new Authorizer();
            (new ReflectionProperty($authorizer, 'grantResolver'))->setValue(
                $authorizer,
                new class($harness) implements SubjectGrantResolverInterface {
                    public function __construct(private readonly AccessHarness $harness) {}

                    public function resolve(SubjectInterface $subject): SubjectGrantSet
                    {
                        return new SubjectGrantSet(
                            new CapabilityGrantSet($this->harness->grantedCapabilities),
                            new PermissionGrantSet($this->harness->grantedPermissions),
                        );
                    }
                },
            );
            $authorization = new AuthorizationListener();
            (new ReflectionProperty($authorization, 'authorizer'))->setValue($authorization, $authorizer);

            $listeners = [
                AuthorizationListener::class => $authorization,
                \Semitexa\Api\Pipeline\RequiresScopeListener::class => new \Semitexa\Api\Pipeline\RequiresScopeListener(),
            ] + $listeners;
            $bindings[PreHydrationAuthGateInterface::class] = new PreHydrationAuthGate();
        }

        $bindings[PipelineListenerRegistry::class] = self::listenerRegistry(array_keys($listeners));

        return new InMemoryContainer($bindings + $listeners);
    }

    /**
     * The real registry, holding exactly these AuthCheck listeners in this
     * order. Built past its constructor: discovery over the whole classmap is
     * what it would otherwise do, and what a unit test must not.
     *
     * @param list<string> $classes
     */
    private static function listenerRegistry(array $classes): PipelineListenerRegistry
    {
        $registry = (new ReflectionClass(PipelineListenerRegistry::class))->newInstanceWithoutConstructor();
        $listeners = [];
        foreach ($classes as $priority => $class) {
            $listeners[] = ['class' => $class, 'phase' => AuthCheck::class, 'priority' => $priority];
        }
        (new ReflectionProperty($registry, 'listenersByPhase'))->setValue($registry, [AuthCheck::class => $listeners]);
        (new ReflectionProperty($registry, 'built'))->setValue($registry, true);

        return $registry;
    }

    private function routeRegistry(): RouteRegistry
    {
        $registry = new RouteRegistry();
        foreach (self::payloads() as $name => [$payloadClass, $handlerClass]) {
            $public = (new ReflectionClass($payloadClass))->getAttributes(\Semitexa\Core\Attribute\AsPublicPayload::class) !== [];
            $registry->register([
                'path' => '/' . str_replace('.', '/', $name),
                'methods' => ['GET'],
                'name' => $name,
                'class' => $payloadClass,
                'responseClass' => RuntimeResourceFixture::class,
                'handlers' => [['class' => $handlerClass]],
                'type' => 'http-request',
                'accessType' => $public ? PayloadAccessType::Public : PayloadAccessType::Protected,
            ]);
        }

        return $registry;
    }
}
