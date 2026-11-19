<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture\Access;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Graphql\Attribute\ExposeAsGraphql;

/** Protected: the nearest class that declares an access type decides, not an ancestor. */
#[AsProtectedPayload(path: '/access/child', methods: ['GET'])]
#[ExposeAsGraphql(field: 'childThing', rootType: 'query')]
final class ProtectedChildPayloadFixture extends PublicParentPayloadFixture
{
}
