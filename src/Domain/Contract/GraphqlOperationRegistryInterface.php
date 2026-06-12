<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Domain\Contract;

use Semitexa\Graphql\Discovery\ResolvedGraphqlOperation;

/**
 * Read-only registry of GraphQL-ready operations discovered from Semitexa
 * Payload DTOs explicitly marked with #[ExposeAsGraphql].
 *
 * This contract exists so future schema builders and `/graphql` transports can
 * enumerate a stable, typed set of operations without coupling themselves to
 * route discovery internals or re-running reflection logic.
 */
interface GraphqlOperationRegistryInterface
{
    /**
     * Return every GraphQL-ready operation known to the application.
     *
     * @return list<ResolvedGraphqlOperation>
     */
    public function all(): array;

    /**
     * Return only root-level GraphQL queries.
     *
     * @return list<ResolvedGraphqlOperation>
     */
    public function queries(): array;

    /**
     * Return only root-level GraphQL mutations.
     *
     * @return list<ResolvedGraphqlOperation>
     */
    public function mutations(): array;

    /**
     * Return only root-level GraphQL subscriptions.
     *
     * @return list<ResolvedGraphqlOperation>
     */
    public function subscriptions(): array;

    /**
     * Find an operation by root type and public field name.
     */
    public function find(string $rootType, string $field): ?ResolvedGraphqlOperation;
}
