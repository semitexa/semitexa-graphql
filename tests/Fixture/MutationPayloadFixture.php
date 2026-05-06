<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture;

use Semitexa\Graphql\Attribute\ExposeAsGraphql;

#[ExposeAsGraphql(field: 'bar', rootType: 'mutation')]
final class MutationPayloadFixture {}
