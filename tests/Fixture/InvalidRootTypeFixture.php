<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture;

use Semitexa\Graphql\Attribute\ExposeAsGraphql;

/** Invalid rootType — must be rejected by the registry. */
#[ExposeAsGraphql(field: 'invalid', rootType: 'subscription')]
final class InvalidRootTypeFixture {}
