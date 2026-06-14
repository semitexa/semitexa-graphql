<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Discovery;

/**
 * Immutable snapshot of a single GraphQL-ready Semitexa operation.
 *
 * This DTO is intentionally transport-agnostic. It gives future schema builders,
 * introspection endpoints, and GraphQL executors a normalized view of an
 * operation derived from the Semitexa route system.
 */
final readonly class ResolvedGraphqlOperation
{
    /**
     * @param list<string> $httpMethods
     * @param list<string> $handlerClasses
     * @param list<string> $watchScopes Resource scope keys a `subscription`
     *        rides; populated from `#[ExposeAsGraphql(watchScopes: ...)]`.
     *        DECLARED here, consumed in Phase 4 (Redis subscription registration).
     */
    public function __construct(
        public string $field,
        public string $rootType,
        public string $payloadClass,
        public ?string $outputClass,
        public string $routeName,
        public string $path,
        public array $httpMethods,
        public array $handlerClasses,
        public string $responseClass,
        public string $description,
        public bool $list = false,
        public array $watchScopes = [],
        /**
         * Registry TYPE handle of the `#[ResourceObject]` this operation
         * returns, resolved from the route's `RouteContract` (the same contract
         * OpenAPI reads). `null` when the route exposes no resource. This is the
         * type source that lets `#[ExposeAsGraphql]` drop its `output:` param.
         */
        public ?string $resourceType = null,
    ) {}

    public function isQuery(): bool
    {
        return $this->rootType === 'query';
    }

    public function isMutation(): bool
    {
        return $this->rootType === 'mutation';
    }

    public function isSubscription(): bool
    {
        return $this->rootType === 'subscription';
    }
}
