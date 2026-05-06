<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture;

use Semitexa\Graphql\Attribute\ExposeAsGraphql;

/** rootType is normalized via strtolower(trim(...)); 'QUERY ' must still parse. */
#[ExposeAsGraphql(field: 'normalized', rootType: 'QUERY ')]
final class CaseInsensitiveRootTypeFixture {}
