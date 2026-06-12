<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture;

use Semitexa\Graphql\Attribute\ExposeAsGraphql;

/**
 * One Payload exposed as TWO schema operations (the repeatable attribute) —
 * a `query` and a `subscription` driven by the same route/handler.
 */
#[ExposeAsGraphql(field: 'things', rootType: 'query', output: \stdClass::class, list: true)]
#[ExposeAsGraphql(
    field: 'thingChanges',
    rootType: 'subscription',
    output: \stdClass::class,
    list: true,
    watchScopes: ['things'],
)]
final class RepeatedExposureFixture {}
