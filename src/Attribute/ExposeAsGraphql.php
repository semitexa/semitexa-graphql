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
 * - which root type it belongs to (`query`, `mutation`, or `subscription`)
 * - which field name should appear in the schema
 * - which typed output contract is safe to expose
 *
 * The attribute is REPEATABLE: a single Payload/route can be exposed as more
 * than one schema operation — e.g. a read route surfaced as both a `query` and
 * a `subscription` field, both driven by the SAME read handler (the design's
 * "subscription ≈ query + watchScopes" model). Each attribute becomes one
 * operation; `rootType:field` must be unique across the whole schema.
 *
 * Usage:
 * ```php
 * #[AsPublicPayload(path: '/graphql-demo/articles', methods: ['GET'])]
 * #[ExposeAsGraphql(field: 'articles', rootType: 'query', output: Article::class, list: true)]
 * #[ExposeAsGraphql(
 *     field: 'articleChanges',
 *     rootType: 'subscription',
 *     output: Article::class,
 *     list: true,
 *     watchScopes: ['playground_articles'],   // consumed in Phase 4 (Redis subscribe)
 * )]
 * final class ArticleListQueryPayload { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ExposeAsGraphql
{
    /**
     * @param list<string> $watchScopes Resource scope keys a `subscription`
     *        operation rides (the analogue of a grid's `liveOn`). DECLARED and
     *        stored here, but NOT consumed in Phase 2 — Phase 4 reads it to
     *        register the Redis `(tenant, scope)` subscription so an ORM write
     *        on a watched scope wakes a held subscription. Ignored for
     *        `query`/`mutation`.
     */
    public function __construct(
        /** Public GraphQL field name, e.g. `productBySlug` or `articleChanges`. */
        public readonly string $field,
        /** GraphQL root type: `query` (reads), `mutation` (writes), `subscription` (live reads). */
        public readonly string $rootType = 'query',
        /** Canonical typed output contract to expose in the GraphQL schema. */
        public readonly ?string $output = null,
        /** Optional human-readable description for generated schema/docs. */
        public readonly string $description = '',
        /** When true, the schema field's type is wrapped as `[Output]` (a list). */
        public readonly bool $list = false,
        /** @var list<string> Resource scope(s) a subscription watches (Phase 4). */
        public readonly array $watchScopes = [],
    ) {}
}
