<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture;

use Semitexa\Graphql\Attribute\ExposeAsGraphql;

/** Invalid rootType — must be rejected by the registry. (`subscription` is now valid.) */
#[ExposeAsGraphql(field: 'invalid', rootType: 'telepathy')]
final class InvalidRootTypeFixture {}
