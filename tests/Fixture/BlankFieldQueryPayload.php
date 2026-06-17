<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture;

use Semitexa\Graphql\Attribute\ExposeAsGraphql;

/**
 * Exposes a GraphQL query WITHOUT declaring `field:` — the registry must derive
 * the field name from this class name: BlankFieldQueryPayload -> `blankField`.
 */
#[ExposeAsGraphql(rootType: 'query')]
final class BlankFieldQueryPayload {}
