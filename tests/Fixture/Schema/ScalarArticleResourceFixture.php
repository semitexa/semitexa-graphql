<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture\Schema;

use Semitexa\Core\Resource\Attribute\ResourceField;
use Semitexa\Core\Resource\Attribute\ResourceId;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\ResourceObjectInterface;

/**
 * A minimal scalar-only `#[ResourceObject]` used to prove that a GraphQL
 * output type can be derived from the same canonical Resource contract that
 * OpenAPI consumes — no `#[ExposeAsGraphql(output: ...)]` DTO required.
 */
#[ResourceObject(type: 'graphql.test.article')]
final readonly class ScalarArticleResourceFixture implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId]
        public string $id,

        #[ResourceField(description: 'Article title.')]
        public string $title,

        #[ResourceField]
        public ?int $views,

        #[ResourceField]
        public bool $published,
    ) {
    }
}
