<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Attribute;

use Attribute;

/**
 * Opts a Payload DTO into GraphQL discovery.
 *
 * Exposure is opt-in at the OPERATION level: Semitexa does not assume every HTTP
 * route is a good GraphQL field. But the marker carries no TYPE information — the
 * output type is resolved from the route's contract (the same #[ResourceObject]
 * OpenAPI reads) — and `rootType` DERIVES from the HTTP method when omitted
 * (`GET`/`HEAD` → query, `POST`/`PUT`/`PATCH`/`DELETE` → mutation). The field
 * name itself DERIVES from the Payload class name when omitted (see
 * {@see \Semitexa\Graphql\Discovery\GraphqlFieldNameDeriver}). Only what neither
 * a route nor the class name can imply needs declaring:
 * - `field` — ONLY as an override when the derived name would be wrong, or to
 *   disambiguate a repeated exposure on one Payload
 * - `rootType: 'subscription'` — a live read has no HTTP-method analogue
 * - any `rootType` that intentionally diverges from the HTTP-method convention
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
 * #[ExposeAsGraphql(field: 'articles', list: true)]              // rootType derived: query
 * #[ExposeAsGraphql(
 *     field: 'articleChanges',
 *     rootType: 'subscription',                                  // explicit: no HTTP analogue
 *     list: true,
 *     watchScopes: ['playground_articles'],
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
        /**
         * Public GraphQL field name, e.g. `productBySlug` or `articleChanges`.
         * OPTIONAL override: when `null`/blank, the field name DERIVES from the
         * Payload class name (see {@see GraphqlFieldNameDeriver} — strip
         * `Payload` + a `Query`/`Mutation`/`Subscription` token, lowerCamelCase).
         * Declare it only when the convention would lie (e.g. a `…ByIdQueryPayload`
         * exposed as the bare `customer`) or to disambiguate repeated exposures.
         */
        public readonly ?string $field = null,
        /**
         * GraphQL root type: `query` (reads), `mutation` (writes), `subscription`
         * (live reads). `null` (the default) means DERIVE from the route's HTTP
         * method — `GET`/`HEAD` → query, `POST`/`PUT`/`PATCH`/`DELETE` → mutation.
         * `subscription` must always be declared explicitly.
         */
        public readonly ?string $rootType = null,
        /** Canonical typed output contract to expose in the GraphQL schema. */
        public readonly ?string $output = null,
        /** When true, the schema field's type is wrapped as `[Output]` (a list). */
        public readonly bool $list = false,
        /** @var list<string> Resource scope(s) a subscription watches (Phase 4). */
        public readonly array $watchScopes = [],
    ) {}
}
