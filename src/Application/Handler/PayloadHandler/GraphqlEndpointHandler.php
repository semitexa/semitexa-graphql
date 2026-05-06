<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Graphql\Application\Payload\Request\GraphqlEndpointPayload;
use Semitexa\Graphql\Application\Resource\Response\GraphqlEndpointResource;
use Semitexa\Graphql\Domain\Contract\GraphqlExecutorInterface;

/**
 * The POST /graphql adapter.
 *
 * The handler stays a thin adapter: it delegates to GraphqlExecutorInterface
 * and only owns the conversion from the validated Payload to the Resource
 * shape. The executor is the seam where webonyx lives — the handler never
 * imports webonyx types.
 *
 * Status code stays 200 for executed-but-failed operations, per the GraphQL
 * spec (errors live in the response body's `errors` array). Pre-execution
 * shape failures (missing query) are caught upstream by ValidationException.
 */
#[AsPayloadHandler(payload: GraphqlEndpointPayload::class, resource: GraphqlEndpointResource::class)]
final class GraphqlEndpointHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected GraphqlExecutorInterface $executor;

    public function handle(GraphqlEndpointPayload $payload, GraphqlEndpointResource $resource): GraphqlEndpointResource
    {
        $result = $this->executor->execute(
            query: $payload->getQuery(),
            variables: $payload->getVariables(),
            operationName: $payload->getOperationName(),
        );

        return $resource->withResult($result);
    }
}
