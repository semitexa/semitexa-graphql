<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Domain\Contract;

/**
 * A Resource that states its own GraphQL value.
 *
 * The default projection reads the Resource's render context. A Resource that
 * writes its HTTP body directly (a REST endpoint rendering JSON, JSON-LD or a
 * negotiated format with setContent()) leaves that context empty on purpose:
 * a non-empty render context makes the HTTP renderer replace the body. Such a
 * Resource implements this contract instead, and the field resolves to the
 * typed output it returns — the `output:` declared on #[ExposeAsGraphql].
 *
 * Measured 2026-10-01 on the demo catalogue: `{ products { total } }` and
 * `productBySlug` both returned null while the REST routes they share a
 * handler with answered 200, because their body never reached the context.
 */
interface GraphqlProjectionInterface
{
    public function toGraphqlValue(): mixed;
}
