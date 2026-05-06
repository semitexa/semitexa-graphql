<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Domain\Contract;

use GraphQL\Type\Schema;

/**
 * Provides the (cached) webonyx Schema for the application's discovered
 * GraphQL operations.
 *
 * Returning `GraphQL\Type\Schema` is intentional and the only place webonyx
 * types appear in a Semitexa-owned contract — the schema object IS the
 * webonyx type system, so abstracting it would just mean re-exporting every
 * webonyx class. Implementation detail leakage is bounded to this single
 * contract; callers above the runtime layer (executor, endpoint) never see
 * webonyx types.
 */
interface SchemaProviderInterface
{
    public function getSchema(): Schema;
}
