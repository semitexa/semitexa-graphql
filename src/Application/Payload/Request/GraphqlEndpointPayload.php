<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Validation\Trait\NotBlankValidationTrait;
use Semitexa\Graphql\Application\Resource\Response\GraphqlEndpointResource;

/**
 * POST /graphql
 *
 * The single Semitexa entry point for GraphQL execution. Accepts the
 * standard GraphQL-over-HTTP body shape: `{ query, variables?, operationName? }`.
 *
 * Hydration is a touch unusual for this Payload: `variables` is always an
 * object/map at the wire level and we keep it as `array<string, mixed>` for
 * the runtime. The setter accepts mixed so the upstream JSON decoder doesn't
 * lose nested structure.
 *
 * Validation is shape-only — `query` must be a non-empty string. GraphQL
 * parse / validation failures are produced by the executor and surface in
 * the response's `errors` array, not as Semitexa ValidationException.
 *
 * **Request format (`consumes`).** Only `application/json` is advertised.
 * The GraphQL-over-HTTP specification mandates JSON request bodies of the
 * form `{ query, variables?, operationName? }`, and that is what every
 * conforming GraphQL client sends. Semitexa's PayloadHydrator can also
 * decode XML request bodies via SimpleXML, but exposing that on a
 * `/graphql` endpoint would invite non-spec usage; the alternative GraphQL
 * Resource-DTO selection-set bridge handles those exotic shapes elsewhere.
 *
 * **Response formats (`produces`).** Three formats are advertised — the
 * full set that the route's renderer pipeline can actually deliver for a
 * `ResourceResponse` carrying an associative render context (the GraphQL
 * execution envelope `{ data, errors?, extensions? }`):
 *
 *   - `application/json`  — canonical GraphQL response; the default when
 *     Accept is missing or `*\/*`. Routed through `ResponseRenderer::renderJson`.
 *   - `application/xml`   — the envelope is structurally array-compatible
 *     with `ResponseRenderer::renderXml`'s SimpleXML conversion. Useful for
 *     non-JS consumers (analytics, log scrapers); not GraphQL-spec wire
 *     format but a legitimate framework rendering.
 *   - `text/plain`        — `ResponseRenderer::renderText` emits a
 *     pretty-printed JSON dump under `text/plain`. Handy for `curl`-style
 *     debugging of the live endpoint.
 *
 * Other framework-supported MIME types are deliberately NOT advertised
 * here:
 *
 *   - `application/ld+json`             — the negotiator routes it to
 *     `renderJson` which sets Content-Type back to `application/json` and
 *     emits no `@context` / `@type`. Advertising would be a contract lie.
 *   - `application/graphql-response+json` — only the Resource-DTO
 *     `GraphqlResourceRenderer` produces this MIME, and that path requires
 *     a `ResourceObjectInterface` DTO with metadata, not the bare execution
 *     envelope this endpoint emits.
 *   - `text/html`                       — the endpoint has no Twig render
 *     handle; `ResponseRenderer::renderLayout` would have nothing to render.
 *   - `application/vnd.api+json`        — no renderer in the framework.
 *
 * Adding any of the above without the corresponding renderer behaviour
 * would falsely advertise capability that the response pipeline cannot
 * actually deliver.
 */
#[AsPublicPayload(
    path: 'env::SEMITEXA_GRAPHQL_ROUTE_PATH::/graphql',
    methods: ['POST'],
    name: 'graphql.endpoint',
    responseWith: GraphqlEndpointResource::class,
    consumes: ['application/json'],
    produces: [
        'application/json',
        'application/xml',
        'text/plain'
    ],
)]
final class GraphqlEndpointPayload
{
    use NotBlankValidationTrait;

    private string $query = '';

    /** @var array<string, mixed>|null */
    private ?array $variables = null;

    private ?string $operationName = null;

    public function getQuery(): string
    {
        return $this->query;
    }

    public function setQuery(string $query): void
    {
        $this->query = self::requireNotBlank(
            'query',
            $query,
            'GraphQL `query` field is required and must be a non-empty string.',
        );
    }

    /** @return array<string, mixed>|null */
    public function getVariables(): ?array
    {
        return $this->variables;
    }

    public function setVariables(mixed $variables): void
    {
        if ($variables === null || $variables === '') {
            $this->variables = null;
            return;
        }
        if (is_array($variables)) {
            /** @var array<string, mixed> $variables */
            $this->variables = $variables;
            return;
        }
        // Some clients send `variables` as a JSON-encoded string; accept that.
        if (is_string($variables)) {
            $decoded = json_decode($variables, true);
            if (is_array($decoded)) {
                $this->variables = $decoded;
                return;
            }
            throw new \InvalidArgumentException(
                "GraphQL \`variables\` field must be an object (or a JSON string encoding an object). Received invalid string: " . $variables
            );
        }

        throw new \InvalidArgumentException(
            "GraphQL \`variables\` field must be an object/map. Received type: " . get_debug_type($variables)
        );
    }

    public function getOperationName(): ?string
    {
        return $this->operationName;
    }

    public function setOperationName(?string $operationName): void
    {
        if ($operationName === null) {
            $this->operationName = null;
            return;
        }
        $trimmed = trim($operationName);
        $this->operationName = $trimmed === '' ? null : $trimmed;
    }
}
