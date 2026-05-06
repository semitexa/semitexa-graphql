<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture;

use Semitexa\Graphql\Attribute\ExposeAsGraphql;

#[ExposeAsGraphql(field: 'foo', rootType: 'query', output: \stdClass::class, description: 'fixture query')]
final class QueryPayloadFixture {}
