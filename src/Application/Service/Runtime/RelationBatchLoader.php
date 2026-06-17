<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Application\Service\Runtime;

use GraphQL\Deferred;
use Semitexa\Core\Resource\ResourceIdentity;

/**
 * Per-execution DataLoader for relation fields: collapses the N+1 that a naive
 * per-parent resolver would cause on a list query.
 *
 * webonyx resolves a query level breadth-first and drains all `Deferred`s
 * queued at that level before descending. So when `{ customers { addresses } }`
 * resolves, every sibling customer's `addresses` resolver runs first (each only
 * BUFFERS its parent identity and returns a `Deferred`); the first `Deferred`
 * callback to run then dispatches ONE `resolveBatch()` for the whole bucket and
 * the rest read from the cached result. One `#[ResolveWith]` resolver call per
 * (resolver class) per level, instead of one per parent.
 *
 * A fresh instance is created per GraphQL execution and handed to webonyx as the
 * context value, so its buffer never leaks across requests on a long-lived
 * Swoole worker. Stateful by design — NOT a container service.
 */
final class RelationBatchLoader
{
    /** @var array<string, array<string, ResourceIdentity>> resolverClass → urn → identity (pending) */
    private array $buffer = [];

    /** @var array<string, array<string, mixed>> resolverClass → urn → resolved value */
    private array $results = [];

    public function __construct(private readonly LazyRelationResolver $resolver)
    {
    }

    /**
     * Buffer one parent identity and return a Deferred that yields this parent's
     * relation value once the bucket is dispatched.
     */
    public function load(string $resolverClass, string $parentType, string $parentId, bool $isList): Deferred
    {
        $identity = ResourceIdentity::of($parentType, $parentId);
        $this->buffer[$resolverClass][$identity->urn()] = $identity;

        return new Deferred(function () use ($resolverClass, $identity, $isList): mixed {
            // The first Deferred to fire for this bucket dispatches the whole
            // pending batch; siblings then fall through to the cached results.
            if (isset($this->buffer[$resolverClass])) {
                $this->dispatch($resolverClass);
            }

            $value = $this->results[$resolverClass][$identity->urn()] ?? null;

            return $value ?? ($isList ? [] : null);
        });
    }

    private function dispatch(string $resolverClass): void
    {
        $identities = array_values($this->buffer[$resolverClass]);
        // Clear BEFORE resolving so a deeper level re-buffers into a fresh bucket
        // and triggers its own dispatch.
        unset($this->buffer[$resolverClass]);

        // Merge (never overwrite) so results from an earlier level survive a
        // later-level dispatch that reuses the same resolver class.
        $this->results[$resolverClass] = ($this->results[$resolverClass] ?? [])
            + $this->resolver->loadMany($resolverClass, $identities);
    }
}
