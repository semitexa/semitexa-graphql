<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Application\Resource\Response;

use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Graphql\Domain\Model\GraphqlExecutionResult;

/**
 * JSON resource for the POST /graphql endpoint.
 *
 * Wraps a `GraphqlExecutionResult` so the Core ResponseRenderer JSON-encodes
 * the GraphQL response shape (`{ data, errors?, extensions? }`) without
 * special-casing the route. The handler always sets the result via
 * `withResult()` — partial data + errors stays a 200, GraphQL conventions
 * intentionally don't change HTTP status for resolver-level failures.
 */
final class GraphqlEndpointResource extends ResourceResponse
{
    public function withResult(GraphqlExecutionResult $result): self
    {
        $this->setRenderContext($result->toArray());
        return $this;
    }
}
