<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Application\Service\Runtime;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Exception\AccessDeniedException;
use Semitexa\Core\Exception\AuthenticationException;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Graphql\Application\Payload\Request\GraphqlEndpointPayload;
use Semitexa\Graphql\Application\Resource\Response\GraphqlEndpointResource;
use Semitexa\Graphql\Domain\Contract\GraphqlOperationRegistryInterface;

/**
 * Whether the caller may hold a subscription stream open at all.
 *
 * Every frame of a stream is an execution, so the full operation gate runs
 * inside each one and a refused field rides in the `next` body with its
 * handler never invoked. That is enough to keep the data in, and not enough
 * for the stream: a guest would hold one open and be fed an error frame on
 * every invalidation. So the part of the gate that needs no arguments — the
 * payload's requirements can be enforced, and the caller is who its access
 * type asks for — is asked here too: before the socket is taken, and on every
 * tick.
 */
#[AsService]
final class GraphqlSubscriptionAccess
{
    private const CODE_UNAUTHENTICATED = 'SSE_OPERATION_UNAUTHENTICATED';
    private const CODE_FORBIDDEN = 'SSE_OPERATION_FORBIDDEN';

    public const FRAGMENT_AT_ROOT = 'A subscription over SSE must select its root field directly, not through a fragment.';

    #[InjectAsReadonly]
    protected GraphqlOperationTypeResolver $operationType;

    #[InjectAsReadonly]
    protected GraphqlOperationRegistryInterface $operations;

    #[InjectAsReadonly]
    protected GraphqlOperationGate $operationGate;

    /**
     * Why the caller may not run one of the document's subscription root
     * fields, or null when it may run them all. A field that is not a
     * registered operation is left to execution, which answers it as the
     * GraphQL validation error it is.
     */
    public function refusal(GraphqlEndpointPayload $payload): AuthenticationException|AccessDeniedException|null
    {
        // The executor expands a fragment at the root and runs its fields,
        // while the names read below would not include them: the caller would
        // be checked against none. Such a stream is not opened at all; the
        // same document still runs one-shot, where every field passes the gate.
        if (!$this->operationType->subscriptionRootIsFieldsOnly($payload->getQuery(), $payload->getOperationName())) {
            return new AccessDeniedException(self::FRAGMENT_AT_ROOT);
        }

        $fields = $this->operationType->subscriptionRootFields(
            $payload->getQuery(),
            $payload->getOperationName(),
        );

        foreach ($fields as $field) {
            $operation = $this->operations->find('subscription', $field);
            if ($operation === null) {
                continue;
            }
            try {
                $this->operationGate->admitCaller($operation);
            } catch (AuthenticationException|AccessDeniedException $e) {
                return $e;
            }
        }

        return null;
    }

    /**
     * Answer a refused subscription before any stream state exists: 401 when
     * nobody the operation accepts is signed in, 403 when the operation cannot
     * be called. A transport-level body, like the stream's own mode refusal,
     * with codes of its own so a client can tell the feature being off from an
     * operation being out of reach.
     */
    public function refuse(
        GraphqlEndpointResource $resource,
        AuthenticationException|AccessDeniedException $refusal,
    ): GraphqlEndpointResource {
        $unauthenticated = $refusal instanceof AuthenticationException;

        $resource->setStatusCode(($unauthenticated ? HttpStatus::Unauthorized : HttpStatus::Forbidden)->value);
        $resource->setRenderContext([
            'error' => $refusal->getMessage(),
            'code' => $unauthenticated ? self::CODE_UNAUTHENTICATED : self::CODE_FORBIDDEN,
        ]);

        return $resource;
    }

    /**
     * Run a tick's execution as the re-run it is, so the operations' listeners
     * that count requests do not count it as a new one.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function duringReRun(\Closure $work): mixed
    {
        return $this->operationGate->duringReRun($work);
    }

    /** @internal Test seam: wire collaborators directly without a container. */
    public function setCollaboratorsForTest(
        GraphqlOperationTypeResolver $operationType,
        GraphqlOperationRegistryInterface $operations,
        GraphqlOperationGate $operationGate,
    ): void {
        $this->operationType = $operationType;
        $this->operations = $operations;
        $this->operationGate = $operationGate;
    }
}
