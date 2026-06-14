<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture\Schema;

use Semitexa\Core\Resource\Attribute\ResourceField;
use Semitexa\Core\Resource\Attribute\ResourceId;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\ResourceObjectInterface;

/**
 * A `#[ResourceObject]` whose identifier is NOT named id/uuid/slug — proves
 * forResource() maps the declared id field to GraphQL `ID` from the metadata,
 * not from ScalarTypeMapper's name heuristic.
 */
#[ResourceObject(type: 'graphql.test.ref_id')]
final readonly class RefIdResourceFixture implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId]
        public string $ref,

        #[ResourceField]
        public string $label,
    ) {
    }
}
