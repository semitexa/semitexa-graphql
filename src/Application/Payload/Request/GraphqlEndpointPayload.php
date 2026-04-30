<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Application\Payload\Request;

use Semitexa\Authorization\Attribute\PublicEndpoint;
use Semitexa\Core\Attribute\AsPayload;
use Semitexa\Core\Contract\ValidatablePayload;
use Semitexa\Core\Http\PayloadValidationResult;
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
 */
#[AsPayload(
    path: 'env::SEMITEXA_GRAPHQL_ROUTE_PATH::/graphql',
    methods: ['POST'],
    name: 'graphql.endpoint',
    responseWith: GraphqlEndpointResource::class,
    consumes: ['application/json'],
    produces: ['application/json'],
)]
#[PublicEndpoint]
final class GraphqlEndpointPayload implements ValidatablePayload
{
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
        $this->query = $query;
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
            $this->variables = is_array($decoded) ? $decoded : null;
            return;
        }
        $this->variables = null;
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

    public function validate(): PayloadValidationResult
    {
        $errors = [];
        if ($this->query === '' || trim($this->query) === '') {
            $errors['query'] = ['GraphQL `query` field is required and must be a non-empty string.'];
        }
        return new PayloadValidationResult($errors === [], $errors);
    }
}
