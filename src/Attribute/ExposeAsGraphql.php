<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Attribute;

use Attribute;

/**
 * Opts a Payload DTO into GraphQL discovery.
 *
 * This attribute is intentionally explicit. Semitexa does not assume that every
 * HTTP route is automatically a good GraphQL field. GraphQL exposure only
 * happens when the operation owner declares:
 * - which root type it belongs to (`query` or `mutation`)
 * - which field name should appear in the schema
 * - which typed output contract is safe to expose
 *
 * Usage:
 * ```php
 * #[AsProtectedPayload(path: '/api/v1/products/{slug}', methods: ['GET'])]
 * #[ExposeAsGraphql(field: 'productBySlug', rootType: 'query', output: ProductView::class)]
 * final class ProductDetailPayload { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ExposeAsGraphql
{
    public function __construct(
        /** Public GraphQL field name, e.g. `productBySlug` or `createOrder`. */
        public readonly string $field,
        /** GraphQL root type: `query` for reads, `mutation` for writes. */
        public readonly string $rootType = 'query',
        /** Canonical typed output contract to expose in the GraphQL schema. */
        public readonly ?string $output = null,
        /** Optional human-readable description for generated schema/docs. */
        public readonly string $description = '',
        /** When true, the schema field's type is wrapped as `[Output]` (a list). */
        public readonly bool $list = false,
    ) {}
}
