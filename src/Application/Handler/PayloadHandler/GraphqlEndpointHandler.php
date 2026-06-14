<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Graphql\Application\Payload\Request\GraphqlEndpointPayload;
use Semitexa\Graphql\Application\Resource\Response\GraphqlEndpointResource;
use Semitexa\Graphql\Application\Service\Runtime\GraphqlSseSubscriptionStreamer;
use Semitexa\Graphql\Domain\Contract\GraphqlExecutorInterface;

/**
 * The POST /graphql adapter — dual-mode (PHASE 1).
 *
 * The handler stays a thin adapter: it delegates to GraphqlExecutorInterface
 * and only owns the conversion from the validated Payload to the Resource
 * shape. The executor is the seam where webonyx lives — the handler never
 * imports webonyx types.
 *
 * Dual-mode fork: before the one-shot branch the handler consults
 * {@see GraphqlSseSubscriptionStreamer}. When (and only when) the caller
 * negotiated `Accept: text/event-stream` AND the operation is a `subscription`,
 * the streamer serves a held-open SSE stream and returns the already-sent
 * Resource. In every other case — no SSE header, a query/mutation even WITH the
 * header, a malformed document, or no live socket — it returns `null` and the
 * existing one-shot request-response branch below runs completely unchanged.
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

    #[InjectAsReadonly]
    protected GraphqlSseSubscriptionStreamer $subscriptionStreamer;

    public function handle(GraphqlEndpointPayload $payload, GraphqlEndpointResource $resource): GraphqlEndpointResource
    {
        // Dual-mode fork. Non-null ⇒ served as a held-open SSE subscription.
        $streamed = $this->subscriptionStreamer->tryServe($payload, $resource);
        if ($streamed !== null) {
            return $streamed;
        }

        // --- one-shot request-response branch (unchanged) --------------------
        $result = $this->executor->execute(
            query: $payload->getQuery(),
            variables: $payload->getVariables(),
            operationName: $payload->getOperationName(),
        );

        return $resource->withResult($result);
    }
}
