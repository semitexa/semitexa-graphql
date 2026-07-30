<?php

declare(strict_types=1);

namespace Semitexa\Graphql;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * The package ships no attributes of its own, so there is nothing for a
 * mechanism-level declaration to hang on — and without this the package is
 * invisible to anyone whose project has not installed it, which is precisely
 * the audience worth telling. The convention is one `Capabilities` class per
 * package: a definite place to look, and a definite place for a guard to check.
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'graphql.operations',
    summary: 'Opt-in GraphQL operations discovered from the same Payload DTO, handler and typed output contracts a route already uses.',
    useWhen: 'Clients need to choose the shape of what they fetch, or several views want different slices of the same data.',
    avoidWhen: 'The client needs one fixed shape. A typed payload/handler route is simpler, already discovered, and cheaper to reason about.',
    replaces: [
        'a hand-written endpoint per view shape, each with its own serialiser',
        'stitching several endpoint responses together in the browser',
    ],
)]
final class Capabilities
{
}
