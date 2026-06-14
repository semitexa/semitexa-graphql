<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture\Schema\CollideA;

use Semitexa\Core\Resource\Attribute\ResourceId;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\ResourceObjectInterface;

/** Same short name ("Widget") as CollideB\Widget — used to prove name-collision fail-fast. */
#[ResourceObject(type: 'graphql.test.collide_a')]
final readonly class Widget implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId]
        public string $id,
    ) {
    }
}
