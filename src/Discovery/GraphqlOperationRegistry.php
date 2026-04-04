<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Discovery;

use ReflectionClass;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Contract\RouteInspectionRegistryInterface;
use Semitexa\Graphql\Attributes\ExposeAsGraphql;
use Semitexa\Graphql\Contract\GraphqlOperationRegistryInterface;
use InvalidArgumentException;

/**
 * Default implementation of GraphqlOperationRegistryInterface.
 *
 * Enumerates discovered Semitexa routes through RouteInspectionRegistryInterface,
 * then keeps only Payload DTOs explicitly marked with #[ExposeAsGraphql].
 *
 * This keeps GraphQL opt-in and transport-agnostic:
 * - route discovery still belongs to Core
 * - GraphQL exposure stays explicit at the Payload boundary
 * - future schema/runtime layers can consume a stable registry contract
 */
#[SatisfiesServiceContract(of: GraphqlOperationRegistryInterface::class)]
final class GraphqlOperationRegistry implements GraphqlOperationRegistryInterface
{
    #[InjectAsReadonly]
    protected RouteInspectionRegistryInterface $routes;

    /** @var list<ResolvedGraphqlOperation>|null */
    private ?array $cache = null;

    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $operations = [];
        $seen = [];

        foreach ($this->routes->all() as $route) {
            $payloadClass = $route->requestClass;

            if (!class_exists($payloadClass)) {
                continue;
            }

            $attribute = $this->resolveGraphqlAttribute($payloadClass);
            if ($attribute === null) {
                continue;
            }

            $rootType = $this->normalizeRootType($attribute->rootType, $payloadClass);
            $outputClass = $this->normalizeOutputClass($attribute->output, $payloadClass);
            $operationKey = $rootType . ':' . $attribute->field;

            if (array_key_exists($operationKey, $seen)) {
                throw new InvalidArgumentException(sprintf(
                    'Duplicate GraphQL operation "%s" declared by %s and %s.',
                    $operationKey,
                    $seen[$operationKey],
                    $payloadClass,
                ));
            }

            $seen[$operationKey] = $payloadClass;

            $operations[] = new ResolvedGraphqlOperation(
                field: $attribute->field,
                rootType: $rootType,
                payloadClass: $payloadClass,
                outputClass: $outputClass,
                routeName: $route->name,
                path: $route->path,
                httpMethods: $route->methods,
                handlerClasses: $this->extractHandlerClasses($route->handlers),
                responseClass: $route->responseClass,
                description: $attribute->description,
            );
        }

        $this->cache = $operations;

        return $this->cache;
    }

    public function queries(): array
    {
        return array_values(
            array_filter(
                $this->all(),
                static fn (ResolvedGraphqlOperation $operation): bool => $operation->isQuery(),
            ),
        );
    }

    public function mutations(): array
    {
        return array_values(
            array_filter(
                $this->all(),
                static fn (ResolvedGraphqlOperation $operation): bool => $operation->isMutation(),
            ),
        );
    }

    public function find(string $rootType, string $field): ?ResolvedGraphqlOperation
    {
        $rootType = $this->normalizeRootType($rootType, null);

        foreach ($this->all() as $operation) {
            if ($operation->rootType === $rootType && $operation->field === $field) {
                return $operation;
            }
        }

        return null;
    }

    private function resolveGraphqlAttribute(string $payloadClass): ?ExposeAsGraphql
    {
        $reflection = new ReflectionClass($payloadClass);
        $attributes = $reflection->getAttributes(ExposeAsGraphql::class);

        if ($attributes === []) {
            return null;
        }

        /** @var ExposeAsGraphql */
        return $attributes[0]->newInstance();
    }

    private function normalizeRootType(string $rootType, ?string $payloadClass): string
    {
        $rootType = strtolower(trim($rootType));

        if ($rootType === 'query' || $rootType === 'mutation') {
            return $rootType;
        }

        $target = $payloadClass ?? 'runtime lookup';

        throw new InvalidArgumentException(sprintf(
            'Unsupported GraphQL root type "%s" for %s. Allowed values: query, mutation.',
            $rootType,
            $target,
        ));
    }

    private function normalizeOutputClass(?string $outputClass, string $payloadClass): ?string
    {
        if ($outputClass === null) {
            return null;
        }

        if (class_exists($outputClass)) {
            return $outputClass;
        }

        throw new InvalidArgumentException(sprintf(
            'GraphQL output contract "%s" declared by %s does not exist.',
            $outputClass,
            $payloadClass,
        ));
    }

    /**
     * @param list<array<string,mixed>> $handlers
     * @return list<string>
     */
    private function extractHandlerClasses(array $handlers): array
    {
        $classes = [];

        foreach ($handlers as $handler) {
            $class = $handler['class'] ?? null;
            if (is_string($class) && $class !== '') {
                $classes[] = $class;
            }
        }

        return array_values(array_unique($classes));
    }
}
