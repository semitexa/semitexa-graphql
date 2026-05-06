<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Application\Service\Runtime;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use RuntimeException;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Graphql\Domain\Contract\HandlerInvokerInterface;
use Semitexa\Graphql\Discovery\ResolvedGraphqlOperation;
use Throwable;

/**
 * Resolves the Semitexa Handler from the application container and runs its
 * `handle($payload, $resource)` method.
 *
 * Resolution path:
 *   1. Pick the first handler class declared on the route metadata
 *      (`ResolvedGraphqlOperation::handlerClasses`).
 *   2. Pull it from the container so DI properties are populated.
 *   3. Instantiate the Resource declared by the route's `responseWith`
 *      (`ResolvedGraphqlOperation::responseClass`) — these are zero-arg
 *      constructable in Semitexa's convention.
 *   4. Invoke `$handler->handle($payload, $resource)` and return its result.
 *
 * Validation is enforced at hydration time by Payload setters (any setter
 * may throw `Semitexa\Core\Exception\ValidationException`). The exception
 * bubbles up through this invoker to the GraphQL error mapper, where it
 * surfaces as `extensions.code = VALIDATION_FAILED`.
 *
 * The container is acquired lazily from `ContainerFactory::get()` because
 * the SemitexaContainer is internal framework plumbing that isn't part of
 * the property-injection graph itself. Tests can supply a container via
 * `setContainerForTest()`.
 */
#[SatisfiesServiceContract(of: HandlerInvokerInterface::class)]
final class ContainerHandlerInvoker implements HandlerInvokerInterface
{
    private ?ContainerInterface $containerOverride = null;

    public function invoke(ResolvedGraphqlOperation $operation, object $payload): object
    {
        if ($operation->handlerClasses === []) {
            throw new RuntimeException(sprintf(
                'Operation "%s:%s" has no handler class declared on its route.',
                $operation->rootType,
                $operation->field,
            ));
        }

        $handler = $this->resolveHandler($operation->handlerClasses[0]);
        $resource = $this->instantiateResource($operation->responseClass);

        return $this->callHandle($handler, $payload, $resource);
    }

    /** @internal Test seam: bypass ContainerFactory in unit tests. */
    public function setContainerForTest(?ContainerInterface $container): void
    {
        $this->containerOverride = $container;
    }

    private function resolveHandler(string $class): object
    {
        $container = $this->container();
        if ($container !== null && $container->has($class)) {
            return $container->get($class);
        }
        // No container, or the handler isn't registered (unit-test path).
        // Zero-arg construct it; #[InjectAsReadonly] properties will only
        // be populated when going through the framework, but tests for the
        // invoker can stub the handler.
        return (new ReflectionClass($class))->newInstance();
    }

    private function instantiateResource(string $resourceClass): object
    {
        $ref = new ReflectionClass($resourceClass);
        return $ref->newInstance();
    }

    private function callHandle(object $handler, object $payload, object $resource): object
    {
        if (!method_exists($handler, 'handle')) {
            throw new RuntimeException(sprintf(
                'Handler %s does not declare a handle() method.',
                $handler::class,
            ));
        }
        $result = $handler->handle($payload, $resource);
        if (!is_object($result)) {
            throw new RuntimeException(sprintf(
                'Handler %s::handle() must return a Resource object; got %s.',
                $handler::class,
                get_debug_type($result),
            ));
        }
        return $result;
    }

    private function container(): ?ContainerInterface
    {
        if ($this->containerOverride !== null) {
            return $this->containerOverride;
        }
        try {
            return ContainerFactory::get();
        } catch (Throwable) {
            return null;
        }
    }
}
