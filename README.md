# Semitexa GraphQL

A real, executable GraphQL runtime for Semitexa applications. Built on the same `Payload DTO → Handler → Resource` architecture as the rest of the framework. Powered by [`webonyx/graphql-php`](https://github.com/webonyx/graphql-php) under the hood, kept behind Semitexa-owned contracts so webonyx types do not leak across the codebase.

## Install

Not included by the installer. Add it to an existing project from the project root:

```bash
docker compose run --rm --no-deps --user "$(id -u):$(id -g)" app composer require semitexa/graphql
bin/semitexa server:restart
```

## What this package provides

- `#[ExposeAsGraphql]` attribute — opt a Semitexa Payload DTO into the GraphQL schema.
- A discovered, immutable registry of GraphQL operations (`GraphqlOperationRegistryInterface`).
- A schema builder that turns those operations into a webonyx `Schema` (`SchemaProviderInterface`).
- A runtime executor that parses, validates, and runs GraphQL queries through the existing Semitexa pipeline (`GraphqlExecutorInterface`).
- A predictable error envelope mapped from Semitexa domain exceptions (`GraphqlErrorMapper`).
- A framework-agnostic `GraphqlExecutionResult` DTO that any HTTP transport can serialize.
- The reusable `POST /graphql` HTTP route — `GraphqlEndpointPayload` (under `Semitexa\Graphql\Application\Payload\Request\`), `GraphqlEndpointHandler` (under `Semitexa\Graphql\Application\Handler\PayloadHandler\`) and `GraphqlEndpointResource` (under `Semitexa\Graphql\Application\Resource\Response\`) — declared with `#[AsPublicPayload]` so it is auto-discovered as soon as the package is installed. No per-application wiring required. The route path is configurable per deployment through `.env` (see [Configuring the route path](#configuring-the-route-path)) without forking package code.

Application modules (e.g. a demo under `src/modules/`) only need to declare their domain operations with a payload attribute (`#[AsPublicPayload]`, or `#[AsProtectedPayload]` from semitexa/authorization) plus `#[ExposeAsGraphql]`. Demo handlers and any application-specific schema authoring stay in the application — the package owns transport and runtime, the application owns the schema content.

## Configuring the route path

The default route is `POST /graphql`. The `path` argument on `GraphqlEndpointPayload`'s `#[AsPublicPayload]` uses Semitexa's standard env-driven attribute-value syntax:

```php
#[AsPublicPayload(
    path: 'env::SEMITEXA_GRAPHQL_ROUTE_PATH::/graphql',
    methods: ['POST'],
    name: 'graphql.endpoint',
    /* … */
)]
```

This is the same `env::VAR::default` format the framework already uses for any payload route — `EnvValueResolver` substitutes the value during route discovery, and falls back to the inline default when the variable is unset. See https://semitexa.com/docs/routing/env-route-override for the framework-wide reference.

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

Add `#[ExposeAsGraphql]` to any Payload DTO that already has a payload attribute such as `#[AsPublicPayload]`:

```php
#[AsPublicPayload(
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
| `field`       | ?string  | `null`      | GraphQL field name (e.g. `articles`, `createArticle`). When `null`, derived from the Payload class name. |
| `rootType`    | ?string  | `null`      | `'query'`, `'mutation'` or `'subscription'`. When `null`, derived from the HTTP method (`GET`/`HEAD` → query, otherwise mutation); `subscription` must be declared. |
| `output`      | ?string  | `null`      | FQCN of the typed output DTO (e.g. `Article::class`). When `null`, the field's output type is the catch-all `Json` scalar. |
| `list`        | bool     | `false`     | When `true`, the schema field type is wrapped as `[Output]`. |
| `watchScopes` | list     | `[]`        | Resource scopes a `subscription` watches; ignored for query/mutation. |
| `description` | ?string  | `null`      | Schema description (surfaces in introspection). |

The attribute is repeatable: one Payload can be exposed as several operations (e.g. a `query` and a `subscription`).

## Argument mapping

Each `public function setX(<scalar>): void` setter on the Payload becomes one GraphQL argument named `x`.

- `string`, `int`, `float`, `bool` → `String`, `Int`, `Float`, `Boolean`.
- Setters named `setId` / `setSlug` / `setUuid` are exposed as `ID!` (non-null `ID`).
- Other arguments default to nullable so Payloads only need to set the fields the client actually supplied.
- Setters with non-scalar parameters are skipped — input objects / nested arguments are not supported.

## Output mapping

For each `output:` class:

- Each `public readonly` scalar property becomes a GraphQL field of the same name.
- `string`, `int`, `float`, `bool`, `?T` → standard scalars; nullable types stay nullable.
- Relation fields of a `#[ResourceObject]` output (`#[ResourceRef]`, `#[ResourceRefList]`, `#[ResourceUnion]`, embedded objects and lists) become nested object fields. A relation not nested by the handler is loaded through its `#[ResolveWith]` resolver only when the query selects it, batched once per resolver per level.

The Resource serializer reads the Resource's render context. The convention is: prefer the `data` key when present (matches every JSON Resource in the framework), otherwise return the whole render context. This makes existing Handlers work without changes — the same `Resource` you serve over REST renders correctly under GraphQL.

## How to run the endpoint

Start the application (`bin/semitexa server:start`) and POST to `/graphql` (9502 is the default port; the installer writes the actual one to `.env` as `SWOOLE_PORT`):

```bash
curl -s -X POST http://localhost:9502/graphql \
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

- **Subscriptions are SSE only.** A `subscription` operation streams when the client sends `Accept: text/event-stream` to the same endpoint. Admission is set by `SEMITEXA_GRAPHQL_SSE_MODE` (`disabled`, `authenticated-only` — the default — or `everyone`). No WebSocket transport.
- **No query batching, no persisted queries, no federation.** Out of scope.
- **Only relation fields are batched.** Each root field runs its full Payload→Handler→Resource cycle.
- **No GET execution.** Only `POST /graphql` is supported. GET-based execution and persisted queries are out of scope for the package's HTTP route.
- Field selection is tracked by webonyx but not pushed down to the resolver — the Handler always produces the full Resource, and webonyx then projects the requested fields. That's transparent to clients but means you cannot use unknown selections to skip work in the Handler.

## Running the tests

In the Semitexa workspace (where `packages/semitexa-graphql` is checked out), run the package's PHPUnit tests inside the app container:

```bash
docker compose exec app php vendor/bin/phpunit packages/semitexa-graphql/tests
```

Tests cover: attribute behaviour, registry discovery, scalar mapping, schema generation, payload hydration, handler dispatch, resource serialization, error mapping, and end-to-end query/mutation execution against the demo Article domain.
