<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture\Access;

use Semitexa\Core\Attribute\AsPublicPayload;

/** Public itself; a child that declares its own access type is not. */
#[AsPublicPayload(path: '/access/parent', methods: ['GET'])]
class PublicParentPayloadFixture
{
}
