# Semitexa GraphQL

A real, executable GraphQL runtime for Semitexa applications. Built on the same `Payload DTO → Handler → Resource` architecture as the rest of the framework. Powered by [`webonyx/graphql-php`](https://github.com/webonyx/graphql-php) under the hood, kept behind Semitexa-owned contracts so webonyx types do not leak across the codebase.

## What this package provides

- `#[ExposeAsGraphql]` attribute — opt a Semitexa Payload DTO into the GraphQL schema.
- A discovered, immutable registry of GraphQL operations (`GraphqlOperationRegistryInterface`).
- A schema builder that turns those operations into a webonyx `Schema` (`SchemaProviderInterface`).
- A runtime executor that parses, validates, and runs GraphQL queries through the existing Semitexa pipeline (`GraphqlExecutorInterface`).
- A predictable error envelope mapped from Semitexa domain exceptions (`GraphqlErrorMapper`).
- A framework-agnostic `GraphqlExecutionResult` DTO that any HTTP transport can serialize.
- The reusable `POST /graphql` HTTP route — `GraphqlEndpointPayload` (under `Semitexa\Graphql\Application\Payload\Request\`), `GraphqlEndpointHandler` (under `Semitexa\Graphql\Application\Handler\PayloadHandler\`) and `GraphqlEndpointResource` (under `Semitexa\Graphql\Application\Resource\Response\`) — declared with `#[AsPayload]` so it is auto-discovered as soon as the package is installed. No per-application wiring required. The route path is configurable per deployment through `.env` (see [Configuring the route path](#configuring-the-route-path)) without forking package code.

Application modules (e.g. a demo under `src/modules/`) only need to declare their domain operations with `#[AsPayload] + #[ExposeAsGraphql]`. The interactive runner page, demo handlers, and any application-specific schema authoring stay in the application — the package owns transport and runtime, the application owns the schema content. Playground does not own the GraphQL HTTP route.

The interactive demo at `GET /graphql-showcase` (lives in Playground, not here) walks a developer through four real surfaces: the package's POST /graphql runner, the Resource DTO multi-profile route at `/playground/customers/{id}` (JSON / JSON-LD / GraphQL response), `?include=` lazy relation expansion, and the `?query=` selection-set bridge. All four sections fire real HTTP — none are mocked.

## Configuring the route path

The default route is `POST /graphql`. The `path` argument on `GraphqlEndpointPayload`'s `#[AsPayload]` uses Semitexa's standard env-driven attribute-value syntax:

```php
#[AsPayload(
    path: 'env::SEMITEXA_GRAPHQL_ROUTE_PATH::/graphql',
    methods: ['POST'],
    name: 'graphql.endpoint',
    /* … */
)]
```

This is the same `env::VAR::default` format the framework already uses for any `#[AsPayload]` route — `EnvValueResolver` substitutes the value during route discovery, and falls back to the inline default when the variable is unset. See `packages/semitexa-core/docs/PAYLOAD_ENV_ROUTE_OVERRIDES.md` for the framework-wide reference.

To customise the public route in a deployment, set `SEMITEXA_GRAPHQL_ROUTE_PATH` in `.env` (or in the process environment). Common values:

```dotenv
# .env
SEMITEXA_GRAPHQL_ROUTE_PATH=/api/graphql
# or /internal/graphql, /admin/graphql, etc.
```

Leave the variable unset (or commented) to keep the default `/graphql`. Verify the registered route at any time with `bin/semitexa routes:list | grep graphql`.

## How `Payload → Handler → Resource` is preserved

Resolvers in this package are intentionally thin. For each `#[ExposeAsGraphql]` field, the resolver:

1. takes the GraphQL arguments,
2. hydrates a fresh instance of the Payload DTO via setters (`PayloadHydratorInterface`); each setter validates its argument and may throw `Semitexa\Core\Exception\ValidationException`, which surfaces as a `VALIDATION_FAILED` GraphQL error,
3. resolves the bound Handler from the application container, including its `#[InjectAsReadonly]` dependencies (`HandlerInvokerInterface`),
4. instantiates the Resource declared by the route,
5. invokes `Handler::handle($payload, $resource)`,
6. serializes the returned Resource for GraphQL (`ResourceSerializerInterface`).

Business logic stays in Handlers. The GraphQL layer is a transport.

## Exposing a Semitexa operation as GraphQL

Add `#[ExposeAsGraphql]` to any Payload DTO that already has `#[AsPayload]`:

```php
#[AsPayload(
    path: '/graphql-demo/articles',
    methods: ['GET'],
    name: 'graphql.demo.articles.list',
    responseWith: ArticleCollectionResource::class,
)]
#[ExposeAsGraphql(
    field: 'articles',
    rootType: 'query',
    output: Article::class,
    description: 'List demo articles.',
    list: true,
)]
final class ArticleListQueryPayload { /* … */ }
```

That's all that's required. Discovery picks it up at boot, the schema builder produces a `[Article]` field on the root `Query` type, and the existing `ArticleListQueryHandler` (declared via `#[AsPayloadHandler(payload: …, resource: …)]`) runs at field resolution.

### Attribute reference

| Argument      | Type     | Default     | Meaning |
|---------------|----------|-------------|---------|
| `field`       | string   | required    | GraphQL field name (e.g. `articles`, `createArticle`). |
| `rootType`    | string   | `'query'`   | `'query'` or `'mutation'`. |
| `output`      | ?string  | `null`      | FQCN of the typed output DTO (e.g. `Article::class`). When `null`, the field's output type is the catch-all `Json` scalar. |
| `description` | string   | `''`        | Schema description (surfaces in introspection). |
| `list`        | bool     | `false`     | When `true`, the schema field type is wrapped as `[Output]`. |

## Argument mapping

Each `public function setX(<scalar>): void` setter on the Payload becomes one GraphQL argument named `x`.

- `string`, `int`, `float`, `bool` → `String`, `Int`, `Float`, `Boolean`.
- Setters named `setId` / `setSlug` / `setUuid` are exposed as `ID!` (non-null `ID`).
- Other arguments default to nullable so Payloads only need to set the fields the client actually supplied.
- Setters with non-scalar parameters are skipped — input objects / nested arguments are tracked under `ep-graphql-nested-resources`.

## Output mapping

For each `output:` class:

- Each `public readonly` scalar property becomes a GraphQL field of the same name.
- `string`, `int`, `float`, `bool`, `?T` → standard scalars; nullable types stay nullable.
- Nested objects, lists of objects, embedded resources are not modeled in this iteration — see `ep-graphql-nested-resources`.

The Resource serializer reads the Resource's render context. The convention is: prefer the `data` key when present (matches every JSON Resource in the framework), otherwise return the whole render context. This makes existing Handlers work without changes — the same `Resource` you serve over REST renders correctly under GraphQL.

## How to run the endpoint

Start the application (`bin/semitexa server:start`) and POST to `/graphql`:

```bash
curl -s -X POST http://localhost:8080/graphql \
  -H 'Content-Type: application/json' \
  --data '{
    "query": "query { articles { id title published } }",
    "variables": null,
    "operationName": null
  }' | jq .
```

The response shape is the standard GraphQL-over-HTTP envelope:

```json
{
  "data": { "articles": [/* … */] }
}
```

Or, on failure:

```json
{
  "errors": [
    {
      "message": "Article #missing not found.",
      "extensions": { "code": "NOT_FOUND", "http_status": 404 },
      "locations": [{ "line": 1, "column": 9 }],
      "path": ["articleById"]
    }
  ],
  "data": { "articleById": null }
}
```

## How errors are shaped

Errors carry a stable `extensions.code`. Mapping table:

| Trigger                                  | `extensions.code`        | HTTP echo |
|------------------------------------------|--------------------------|-----------|
| GraphQL parse / validation failure       | `GRAPHQL_VALIDATION`     | n/a       |
| `Semitexa\Core\Exception\ValidationException`     | `VALIDATION_FAILED`      | 422       |
| `Semitexa\Core\Exception\NotFoundException`       | `NOT_FOUND`              | 404       |
| `Semitexa\Core\Exception\AccessDeniedException`   | `FORBIDDEN`              | 403       |
| `Semitexa\Core\Exception\AuthenticationException` | `UNAUTHENTICATED`        | 401       |
| `Semitexa\Core\Exception\RateLimitException`      | `RATE_LIMITED`           | 429       |
| any other `Throwable`                    | `INTERNAL_SERVER_ERROR`  | 500       |

The runtime never leaks original exception messages or stack traces for `INTERNAL_SERVER_ERROR` — the response carries a generic message.

## HTTP status conventions

The endpoint always returns `200 OK` for executed-but-failed operations (errors live in the response body — the GraphQL way). Pre-execution shape failures (missing `query`, malformed JSON) flow through Core's regular validation pipeline and surface as `422`/`400`.

## Current limitations

- **No nested resources.** Output types are scalar-only objects; nested objects and lists of nested objects are not in the schema yet. Tracked: `ep-graphql-nested-resources`.
- **No subscriptions, no batching, no persisted queries, no federation.** Out of scope.
- **No `DataLoader`-style batching of resolvers.** Each field runs its full Payload→Handler→Resource cycle.
- **No GET execution.** Only `POST /graphql` is supported. GET-based execution and persisted queries are out of scope for the package's HTTP route.
- Field selection is tracked by webonyx but not pushed down to the resolver — the Handler always produces the full Resource, and webonyx then projects the requested fields. That's transparent to clients but means you cannot use unknown selections to skip work in the Handler.

## Running the tests

```bash
# Package tests only
bin/semitexa test:run --testsuite semitexa-graphql

# Filter by name
bin/semitexa test:run --filter "Graphql"

# Full suite
bin/semitexa test:run
```

Tests cover: attribute behaviour, registry discovery, scalar mapping, schema generation, payload hydration, handler dispatch, resource serialization, error mapping, and end-to-end query/mutation execution against the demo Article domain.
