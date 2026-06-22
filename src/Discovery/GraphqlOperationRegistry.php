<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Discovery;

use ReflectionClass;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Contract\RouteContractAssemblerInterface;
use Semitexa\Core\Contract\RouteInspectionRegistryInterface;
use Semitexa\Core\Http\RouteContract;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Graphql\Attribute\ExposeAsGraphql;
use Semitexa\Graphql\Domain\Contract\GraphqlOperationRegistryInterface;
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

    /**
     * Core seam that joins a route to its resolved `#[ResourceObject]` and
     * collection facts. Used to derive an operation's output type + `list`
     * from the route contract instead of from `#[ExposeAsGraphql]` params.
     * Optional: when unset (partial test wiring) the registry degrades to the
     * attribute-declared `output:`/`list:` values.
     */
    #[InjectAsReadonly]
    protected RouteContractAssemblerInterface $contracts;

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

            // #[ExposeAsGraphql] is repeatable: one route/handler may surface as
            // several schema operations (e.g. a read exposed as BOTH a query and
            // a subscription, driven by the same handler).
            $attributes = $this->resolveGraphqlAttributes($payloadClass);
            if ($attributes === []) {
                // Not a GraphQL route — skip BEFORE assembling its contract, so a
                // large app pays one RouteContract assembly per EXPOSED route,
                // not one per HTTP route.
                continue;
            }

            // Resolve the route's output contract ONCE per route: the resolved
            // resource type + collection flag are route-level facts shared by
            // every exposure on this payload.
            $contract     = $this->resolveContract($payloadClass, $route->responseClass);
            $resourceType = $contract?->output?->type;
            $isCollection = $contract?->isCollection ?? false;

            foreach ($attributes as $attribute) {
                if ($attribute->rootType !== null && trim($attribute->rootType) !== '') {
                    $rootType = $this->normalizeRootType($attribute->rootType, $payloadClass);
                } else {
                    $rootType = $this->deriveRootTypeFromMethods($route->methods);
                    if ($rootType === null) {
                        // Underivable (a route with no GET/HEAD/POST/PUT/PATCH/DELETE
                        // and no explicit rootType) is a per-operation misconfig —
                        // skip THIS exposure loudly rather than abort discovery for
                        // every other GraphQL operation in the app.
                        StaticLoggerBridge::warning('graphql', sprintf(
                            'Cannot derive a GraphQL rootType for %s on route "%s" from methods '
                            . '[%s]; declare rootType explicitly. Skipping this operation.',
                            $payloadClass,
                            $route->name,
                            implode(', ', $route->methods),
                        ));
                        continue;
                    }
                }
                $outputClass = $this->normalizeOutputClass($attribute->output, $payloadClass);
                // `field:` is now an OPTIONAL override — derive it from the Payload
                // class name when the attribute leaves it blank.
                $field = $this->resolveField($attribute->field, $payloadClass);
                $operationKey = $rootType . ':' . $field;

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
                    field: $field,
                    rootType: $rootType,
                    payloadClass: $payloadClass,
                    outputClass: $outputClass,
                    routeName: $route->name,
                    path: $route->path,
                    httpMethods: $route->methods,
                    handlerClasses: $this->extractHandlerClasses($route->handlers),
                    responseClass: $route->responseClass,
                    // Optional schema-field description from the marker; blank
                    // when not declared (SchemaBuilder maps '' → null, so the
                    // field simply carries no description).
                    description: $attribute->description ?? '',
                    // `list` derives from the route contract's reliable
                    // isCollection signal (true for any collection response,
                    // including bare ones). The attribute flag remains an
                    // explicit override for routes/tests without a contract.
                    list: $attribute->list || $isCollection,
                    watchScopes: $this->normalizeWatchScopes($attribute->watchScopes),
                    resourceType: $resourceType,
                );
            }
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

    public function subscriptions(): array
    {
        return array_values(
            array_filter(
                $this->all(),
                static fn (ResolvedGraphqlOperation $operation): bool => $operation->isSubscription(),
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

    /**
     * Resolve every #[ExposeAsGraphql] attribute declared on the Payload (the
     * attribute is repeatable). Empty list when none are present.
     *
     * @return list<ExposeAsGraphql>
     */
    private function resolveGraphqlAttributes(string $payloadClass): array
    {
        $reflection = new ReflectionClass($payloadClass);
        $attributes = $reflection->getAttributes(ExposeAsGraphql::class);

        $resolved = [];
        foreach ($attributes as $attribute) {
            /** @var ExposeAsGraphql $instance */
            $instance = $attribute->newInstance();
            $resolved[] = $instance;
        }

        return $resolved;
    }

    /**
     * Assemble the route's output contract, degrading to null when the
     * assembler is unwired (partial test construction) or the contract cannot
     * be built — discovery must never break on a single unresolvable route.
     */
    private function resolveContract(string $payloadClass, ?string $responseClass): ?RouteContract
    {
        if (!isset($this->contracts)) {
            return null;
        }

        try {
            return $this->contracts->assemble($payloadClass, $responseClass);
        } catch (\Throwable $e) {
            // Don't let one route's contract failure break GraphQL discovery —
            // but make it observable: with `output:` no longer on the marker, a
            // swallowed failure means the operation silently degrades to the Json
            // scalar instead of its Resource type. Surface it so the misconfig is
            // diagnosable rather than an invisible schema downgrade.
            StaticLoggerBridge::warning('graphql', sprintf(
                'RouteContract assembly failed for %s; GraphQL output type falls back to '
                . 'the Json scalar: %s',
                $payloadClass,
                $e->getMessage(),
            ));

            return null;
        }
    }

    /**
     * Derive a root type from a route's HTTP methods when `#[ExposeAsGraphql]`
     * omits `rootType`: writes (`POST`/`PUT`/`PATCH`/`DELETE`) → mutation,
     * reads (`GET`/`HEAD`) → query. Writes win on a mixed-method route.
     * `subscription` has no HTTP analogue and so cannot be derived — it must be
     * declared explicitly.
     *
     * Returns null when no method is classifiable; the caller skips that single
     * exposure rather than aborting discovery for the whole schema.
     *
     * @param list<string> $methods
     */
    private function deriveRootTypeFromMethods(array $methods): ?string
    {
        $upper = array_map(static fn (mixed $m): string => strtoupper((string) $m), $methods);

        foreach ($upper as $method) {
            if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                return 'mutation';
            }
        }
        foreach ($upper as $method) {
            if (in_array($method, ['GET', 'HEAD'], true)) {
                return 'query';
            }
        }

        return null;
    }

    /**
     * Trim each declared watch scope and drop blanks — including whitespace-only
     * entries, which would otherwise be stored verbatim as undebuggable
     * invalidation-channel keys — storing the trimmed value. Non-strings are
     * dropped.
     *
     * @param array<mixed> $scopes
     * @return list<string>
     */
    private function normalizeWatchScopes(array $scopes): array
    {
        $result = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope)) {
                continue;
            }
            $trimmed = trim($scope);
            if ($trimmed !== '') {
                $result[] = $trimmed;
            }
        }

        return $result;
    }

    /**
     * Resolve an operation's GraphQL field name: the attribute's `field:` when
     * declared (override), otherwise derive it from the Payload class name. A
     * whitespace-only override is treated as absent.
     */
    private function resolveField(?string $declared, string $payloadClass): string
    {
        if ($declared !== null && trim($declared) !== '') {
            return trim($declared);
        }

        return GraphqlFieldNameDeriver::derive($payloadClass);
    }

    private function normalizeRootType(string $rootType, ?string $payloadClass): string
    {
        $rootType = strtolower(trim($rootType));

        if ($rootType === 'query' || $rootType === 'mutation' || $rootType === 'subscription') {
            return $rootType;
        }

        $target = $payloadClass ?? 'runtime lookup';

        throw new InvalidArgumentException(sprintf(
            'Unsupported GraphQL root type "%s" for %s. Allowed values: query, mutation, subscription.',
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
