<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Application\Service\Runtime;

use Closure;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Semitexa\Core\Attribute\AbstractPayloadRoute;
use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\RequiresSignedUrl;
use Semitexa\Core\Auth\AuthBootstrapperFactoryInterface;
use Semitexa\Core\Auth\AuthBootstrapperInterface;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Contract\RouteMetadataResolverInterface;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\DefaultRouteMetadataResolver;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Discovery\ResolvedRouteMetadata;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\Exception\AccessDeniedException;
use Semitexa\Core\Exception\AuthenticationException;
use Semitexa\Core\Lifecycle\CurrentRequestStore;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Pipeline\AuthCheck;
use Semitexa\Core\Pipeline\PipelineExecutor;
use Semitexa\Core\Pipeline\PipelineListenerRegistry;
use Semitexa\Core\Pipeline\PreHydrationAuthGateInterface;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Core\Request;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Graphql\Discovery\ResolvedGraphqlOperation;
use Throwable;

/**
 * Decides whether the caller of `POST /graphql` may run one operation, before
 * its handler is invoked.
 *
 * `/graphql` is a public route, and an operation is another route's payload
 * whose handler is called directly. Nothing that guards that payload over HTTP
 * ran on this path: not its access type, not `#[RequiresPermission]` /
 * `#[RequiresCapability]` / `#[RequiresScope]`, not a tenant, rate-limit or
 * console gate. This class runs them, and it runs the SAME components HTTP
 * runs rather than a copy of their rules:
 *
 *   1. the registered {@see PreHydrationAuthGateInterface}, before the
 *      arguments are hydrated (a guest is refused before any setter runs);
 *   2. hydration and validation (the caller's closure);
 *   3. the route's whole AuthCheck phase through {@see PipelineExecutor::authorize()},
 *      the entry point core already uses to admit a request that runs
 *      elsewhere, with the operation's payload and route and THIS request.
 *
 * What cannot be evaluated is refused, not skipped:
 *
 *   - a payload that is not `#[AsPublicPayload]`, or that declares
 *     `#[RequiresPermission]` / `#[RequiresCapability]`, when no authorization
 *     listener is registered to enforce it;
 *   - `#[RequiresScope]` when its listener is not registered;
 *   - `#[RequiresSignedUrl]`, always: the signature covers the URL of the
 *     operation's own route, and a GraphQL call has no such URL;
 *   - any other `#[Requires…]` attribute, because nothing here can tell that
 *     something enforces it;
 *   - a non-public operation called with no HTTP request to authenticate.
 *
 * Refusals are the domain exceptions the HTTP pipeline throws, so the error
 * mapper answers UNAUTHENTICATED or FORBIDDEN. A refusal decided HERE tells the
 * caller nothing but that: its reason names payload and listener classes, and
 * the caller may be anonymous, so the reason goes to the log.
 */
#[AsService]
final class GraphqlOperationGate
{
    private const AUTHORIZATION_LISTENER = 'Semitexa\\Authorization\\Pipeline\\AuthorizationListener';
    private const SCOPE_LISTENER = 'Semitexa\\Api\\Pipeline\\RequiresScopeListener';

    /**
     * Requirement attribute → the AuthCheck listener that enforces it. Named as
     * strings: both packages are optional beside this one. `null`: nothing can
     * enforce it for a GraphQL call.
     */
    private const ENFORCED_BY = [
        'Semitexa\\Authorization\\Attribute\\RequiresPermission' => self::AUTHORIZATION_LISTENER,
        'Semitexa\\Authorization\\Attribute\\RequiresCapability' => self::AUTHORIZATION_LISTENER,
        'Semitexa\\Api\\Attribute\\RequiresScope' => self::SCOPE_LISTENER,
        RequiresSignedUrl::class => null,
    ];

    /** All a caller is told about a refusal decided here. */
    public const UNAVAILABLE = 'This operation is not available.';

    /** Coroutine-local mark of a re-run tick, set by {@see duringReRun()}. */
    private const RE_RUN_KEY = '__semitexa_graphql_operation_gate_re_run';

    /** @var array<class-string, array{public: bool, requirements: list<string>}> */
    private static array $declared = [];

    /**
     * Where the pipeline's own components come from: the listener registry,
     * the pre-hydration gate, the auth bootstrapper, the routes. Never asked
     * for anything else.
     */
    #[InjectAsReadonly]
    protected ContainerInterface $container;

    private ?ContainerInterface $containerOverride = null;

    /**
     * The whole admission of one call: the caller, then `$hydrate` (arguments
     * to a validated payload), then the AuthCheck phase. Returns the payload
     * the handler may be given.
     *
     * @param Closure(): object $hydrate
     */
    public function admit(ResolvedGraphqlOperation $operation, Closure $hydrate): object
    {
        $call = $this->enter($operation);

        $payload = $hydrate();
        if (!$payload instanceof $operation->payloadClass) {
            // The listeners read the access attributes off the payload they
            // are given; another class would be judged by another policy.
            throw self::unavailable(sprintf(
                'GraphQL operation "%s" was hydrated into %s, not its payload %s.',
                $operation->field,
                $payload::class,
                $operation->payloadClass,
            ));
        }

        if ($call === null) {
            return $payload;
        }

        $context = new RequestPipelineContext(
            requestDto: $payload,
            route: $call['route'],
            request: $call['request'],
            resourceDto: self::resource($operation),
            authBootstrapper: $call['bootstrapper'],
            resolvedMetadata: self::metadata($call['container'], $call['route']),
        );
        // A re-run tick of a held-open subscription is the request already
        // counted: the listeners that count requests read this flag.
        $context->reRun = CoroutineLocal::get(self::RE_RUN_KEY) === true;

        (new PipelineExecutor($call['scope'], $call['container']))->authorize($context);

        return $payload;
    }

    /**
     * The part that needs no arguments: what the payload declares can be
     * enforced here, and the caller is who its access type asks for. A
     * subscription stream is refused on this before it opens, and closed on it
     * when a tick finds the caller gone.
     *
     * @throws AuthenticationException nobody is signed in, or not as the kind of subject the operation needs
     * @throws AccessDeniedException   the operation cannot be called over GraphQL
     */
    public function admitCaller(ResolvedGraphqlOperation $operation): void
    {
        $this->enter($operation);
    }

    /**
     * Run `$work` as a re-run tick of a held-open subscription: the operations
     * it executes are gated like any other, and told apart only by
     * {@see RequestPipelineContext::$reRun}, as the route's own re-run is. The
     * stream that owns the tick says so; nothing here can know it.
     *
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    public function duringReRun(Closure $work): mixed
    {
        $before = CoroutineLocal::get(self::RE_RUN_KEY);
        CoroutineLocal::set(self::RE_RUN_KEY, true);
        try {
            return $work();
        } finally {
            if ($before === true) {
                CoroutineLocal::set(self::RE_RUN_KEY, true);
            } else {
                CoroutineLocal::remove(self::RE_RUN_KEY);
            }
        }
    }

    /** @internal Test seam: the container the pipeline components come from. */
    public function setContainerForTest(?ContainerInterface $container): void
    {
        $this->containerOverride = $container;
    }

    /**
     * Refuse what cannot be enforced, then run the pre-hydration auth gate.
     * Null when there is nothing to run the pipeline against (no container or
     * no request) and the operation asks for nothing.
     *
     * @return array{container: ContainerInterface, scope: RequestScopedContainer, request: Request, route: DiscoveredRoute, bootstrapper: ?AuthBootstrapperInterface}|null
     */
    private function enter(ResolvedGraphqlOperation $operation): ?array
    {
        $declared = self::declared($operation->payloadClass);
        $container = $this->container();
        $listeners = $container === null ? [] : self::authCheckListeners($container);

        foreach ($declared['requirements'] as $attribute) {
            $enforcer = self::ENFORCED_BY[$attribute] ?? null;
            if ($enforcer === null) {
                throw self::unavailable(sprintf(
                    'GraphQL operation "%s" cannot be called over GraphQL: nothing enforces #[%s] of %s here.',
                    $operation->field,
                    self::shortName($attribute),
                    $operation->payloadClass,
                ));
            }
            if (!in_array($enforcer, $listeners, true)) {
                throw self::unavailable(sprintf(
                    'GraphQL operation "%s" is refused: %s declares #[%s], and the listener that enforces it (%s) is not registered.',
                    $operation->field,
                    $operation->payloadClass,
                    self::shortName($attribute),
                    $enforcer,
                ));
            }
        }

        if (!$declared['public'] && !in_array(self::AUTHORIZATION_LISTENER, $listeners, true)) {
            throw self::unavailable(sprintf(
                'GraphQL operation "%s" is refused: %s is not #[AsPublicPayload], and no authorization listener (%s) is registered to enforce its access type.',
                $operation->field,
                $operation->payloadClass,
                self::AUTHORIZATION_LISTENER,
            ));
        }

        $open = $declared['public'] && $declared['requirements'] === [];
        $scope = $container === null ? null : RequestScopedContainer::forCurrentExecution($container);
        $request = $scope === null ? null : self::request($scope);

        if ($container === null || $scope === null || $request === null) {
            if ($open) {
                // An in-process execution (a console command, a test): there
                // is no caller to check, and the operation asks for none.
                return null;
            }

            throw self::unauthenticated(sprintf(
                'GraphQL operation "%s" needs an authenticated caller, and there is no HTTP request to authenticate.',
                $operation->field,
            ));
        }

        $route = self::route($container, $operation);
        if ($route === null) {
            throw self::unavailable(sprintf(
                'GraphQL operation "%s" is refused: its route "%s" is not available for this request.',
                $operation->field,
                $operation->routeName,
            ));
        }
        if ($open && $route->accessType !== PayloadAccessType::Public) {
            // The attribute and the discovered route must agree; where they
            // do not, the stricter reading stands and the listener decides.
            if (!in_array(self::AUTHORIZATION_LISTENER, $listeners, true)) {
                throw self::unavailable(sprintf(
                    'GraphQL operation "%s" is refused: its route is not public, and no authorization listener (%s) is registered.',
                    $operation->field,
                    self::AUTHORIZATION_LISTENER,
                ));
            }
        }

        $factory = $container->has(AuthBootstrapperFactoryInterface::class)
            ? $container->get(AuthBootstrapperFactoryInterface::class)
            : null;
        $bootstrapper = $factory instanceof AuthBootstrapperFactoryInterface ? $factory->create($container, $scope) : null;

        $gate = $container->has(PreHydrationAuthGateInterface::class)
            ? $container->get(PreHydrationAuthGateInterface::class)
            : null;
        if ($gate instanceof PreHydrationAuthGateInterface) {
            $gate->gate(self::barePayload($operation), $request, $bootstrapper);
        } elseif (!$declared['public'] || $route->accessType !== PayloadAccessType::Public) {
            // Nothing would check the caller before the arguments reach the
            // payload's setters: a guest would be answered by them (a
            // VALIDATION_FAILED that tells what they accept) before being
            // refused after hydration.
            throw self::unavailable(sprintf(
                'GraphQL operation "%s" is refused: %s is not public, and no pre-hydration auth gate (%s) is bound to check the caller before its arguments are hydrated.',
                $operation->field,
                $operation->payloadClass,
                PreHydrationAuthGateInterface::class,
            ));
        }

        return [
            'container' => $container,
            'scope' => $scope,
            'request' => $request,
            'route' => $route,
            'bootstrapper' => $bootstrapper,
        ];
    }

    /**
     * What the payload class declares, up its class chain (attributes are not
     * inherited): whether its access type is `#[AsPublicPayload]`, and every `#[Requires…]`
     * attribute. Read by name, so an attribute of a package that is not
     * installed still counts as declared.
     *
     * @return array{public: bool, requirements: list<string>}
     */
    private static function declared(string $payloadClass): array
    {
        if (!class_exists($payloadClass)) {
            return ['public' => false, 'requirements' => []];
        }
        if (isset(self::$declared[$payloadClass])) {
            return self::$declared[$payloadClass];
        }

        // The access type is the nearest class's that declares one, as the
        // policy resolver reads it: a protected child of a public parent is
        // protected. Requirements gather over the whole chain.
        $public = null;
        $requirements = [];
        for ($class = new ReflectionClass($payloadClass); $class !== false; $class = $class->getParentClass()) {
            $access = [];
            foreach ($class->getAttributes() as $attribute) {
                $name = $attribute->getName();
                if (self::isAccessAttribute($name)) {
                    $access[] = $name;
                }
                if (str_starts_with(self::shortName($name), 'Requires')) {
                    $requirements[$name] = true;
                }
            }
            if ($public === null && $access !== []) {
                $public = $access === [AsPublicPayload::class];
            }
        }
        $public ??= false;

        return self::$declared[$payloadClass] = ['public' => $public, 'requirements' => array_keys($requirements)];
    }

    /**
     * A route attribute, which is what states an access type. By class where
     * the class loads, and by its name (`As…Payload`) where it does not: the
     * attribute of a package that is not installed must still count, or the
     * class under it would be read as its public parent.
     */
    private static function isAccessAttribute(string $name): bool
    {
        return is_a($name, AbstractPayloadRoute::class, true)
            || preg_match('/^As\w*Payload$/', self::shortName($name)) === 1;
    }

    /** FORBIDDEN with nothing to read in it; why, for the operator, in the log. */
    private static function unavailable(string $reason): AccessDeniedException
    {
        StaticLoggerBridge::warning('graphql', $reason);

        return new AccessDeniedException(self::UNAVAILABLE);
    }

    private static function unauthenticated(string $reason): AuthenticationException
    {
        StaticLoggerBridge::warning('graphql', $reason);

        return new AuthenticationException();
    }

    /** An instance that carries only its class: the pre-hydration gate reads attributes, not data. */
    private static function barePayload(ResolvedGraphqlOperation $operation): object
    {
        $class = $operation->payloadClass;
        if (!class_exists($class)) {
            throw self::unavailable(sprintf('GraphQL operation "%s" has no payload class.', $operation->field));
        }

        return (new ReflectionClass($class))->newInstance();
    }

    /** @return list<string> the classes of the listeners the AuthCheck phase will run */
    private static function authCheckListeners(ContainerInterface $container): array
    {
        if (!$container->has(PipelineListenerRegistry::class)) {
            return [];
        }
        $registry = $container->get(PipelineListenerRegistry::class);
        if (!$registry instanceof PipelineListenerRegistry) {
            return [];
        }

        return array_column($registry->getListeners(AuthCheck::class), 'class');
    }

    /**
     * The request of this execution: the one its scope holds (a re-run tick
     * rebuilds it from the live session), else the one the pipeline stored.
     */
    private static function request(RequestScopedContainer $scope): ?Request
    {
        if ($scope->holds(Request::class)) {
            $request = $scope->get(Request::class);
            if ($request instanceof Request) {
                return $request;
            }
        }

        return CurrentRequestStore::get();
    }

    /** The operation's own route, with its handlers, as the tenant of this request sees it. */
    private static function route(ContainerInterface $container, ResolvedGraphqlOperation $operation): ?DiscoveredRoute
    {
        if ($operation->routeName === '' || !$container->has(RouteRegistry::class)) {
            return null;
        }
        $routes = $container->get(RouteRegistry::class);
        if (!$routes instanceof RouteRegistry) {
            return null;
        }
        $discovery = $container->has(AttributeDiscovery::class) ? $container->get(AttributeDiscovery::class) : null;

        $route = $routes->findByNameTyped(
            $operation->routeName,
            $discovery instanceof AttributeDiscovery ? $discovery->getHandlerRegistry() : null,
        );

        return $route !== null && $route->requestClass === $operation->payloadClass ? $route : null;
    }

    private static function metadata(ContainerInterface $container, DiscoveredRoute $route): ResolvedRouteMetadata
    {
        $resolver = $container->has(RouteMetadataResolverInterface::class)
            ? $container->get(RouteMetadataResolverInterface::class)
            : null;

        return ($resolver instanceof RouteMetadataResolverInterface ? $resolver : new DefaultRouteMetadataResolver())->resolve($route);
    }

    /** The resource the handler will be given a fresh one of; listeners only inspect it. */
    private static function resource(ResolvedGraphqlOperation $operation): ?object
    {
        try {
            return class_exists($operation->responseClass) ? new ($operation->responseClass)() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function shortName(string $class): string
    {
        $at = strrpos($class, '\\');

        return $at === false ? $class : substr($class, $at + 1);
    }

    private function container(): ?ContainerInterface
    {
        // Unwired (a unit test that built the gate by hand): no pipeline to
        // run, so only an operation that asks for nothing passes.
        return $this->containerOverride ?? ($this->container ?? null);
    }
}
