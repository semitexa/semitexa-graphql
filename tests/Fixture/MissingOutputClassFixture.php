<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture;

use Semitexa\Graphql\Attribute\ExposeAsGraphql;

/** output points at a class that does not exist — must be rejected. */
#[ExposeAsGraphql(field: 'broken', output: 'Definitely\\Not\\A\\Real\\Class')]
final class MissingOutputClassFixture {}
