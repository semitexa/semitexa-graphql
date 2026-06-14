<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture;

use Semitexa\Graphql\Attribute\ExposeAsGraphql;

/** A `subscription` root operation with declared (Phase-4) watchScopes. */
#[ExposeAsGraphql(
    field: 'thingChanges',
    rootType: 'subscription',
    output: \stdClass::class,
    description: 'fixture subscription',
    list: true,
    watchScopes: ['things', '', 0],
)]
final class SubscriptionPayloadFixture {}
