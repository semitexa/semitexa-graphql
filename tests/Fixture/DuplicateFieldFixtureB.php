<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture;

use Semitexa\Graphql\Attribute\ExposeAsGraphql;

#[ExposeAsGraphql(field: 'sameField', rootType: 'query')]
final class DuplicateFieldFixtureB {}
