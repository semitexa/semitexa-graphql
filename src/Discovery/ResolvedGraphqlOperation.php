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
    ) {}

    public function isQuery(): bool
    {
        return $this->rootType === 'query';
    }

    public function isMutation(): bool
    {
        return $this->rootType === 'mutation';
    }
}
