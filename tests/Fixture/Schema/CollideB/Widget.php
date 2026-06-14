<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture\Schema\CollideB;

use Semitexa\Core\Resource\Attribute\ResourceId;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\ResourceObjectInterface;

/** Same short name ("Widget") as CollideA\Widget — used to prove name-collision fail-fast. */
#[ResourceObject(type: 'graphql.test.collide_b')]
final readonly class Widget implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId]
        public string $id,
    ) {
    }
}
