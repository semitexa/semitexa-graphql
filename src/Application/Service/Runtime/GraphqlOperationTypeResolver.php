<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Application\Service\Runtime;

use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;
use Semitexa\Core\Attribute\AsService;

/**
 * Pre-execution operation-type detection seam.
 *
 * Parses a GraphQL document to its AST and reads the operation kind
 * (`query` / `mutation` / `subscription`) BEFORE any schema validation or
 * execution. This is deliberately schema-independent: the operation kind is a
 * pure AST property of `OperationDefinitionNode`, so it works even though the
 * schema does not (yet) accept a `subscription` root type — that schema lift is
 * a later phase. The single `POST /graphql` handler calls this to decide the
 * SSE-vs-one-shot fork (see {@see GraphqlSseSubscriptionStreamer}).
 *
 * Failure posture: a malformed / unparseable document, an empty query, a
 * document with no operations, or an `operationName` that matches no operation
 * all return `null` ("not a subscription"). The handler then falls through to
 * the unchanged one-shot branch, which surfaces the real parse error in the
 * normal GraphQL `errors` envelope — the fork never 500s on a bad document.
 *
 * It is a container-managed service (parameterless ctor, no dependencies) so it
 * can be injected `#[InjectAsReadonly]` like the executor's collaborators.
 */
#[AsService]
final class GraphqlOperationTypeResolver
{
    /**
     * Resolve the operation kind of the document, honouring `operationName`
     * when the document carries multiple operations; otherwise the single /
     * first operation. Returns `'query' | 'mutation' | 'subscription'`, or
     * `null` when the kind cannot be determined (see class docblock).
     */
    public function resolveOperationType(string $query, ?string $operationName = null): ?string
    {
        if (trim($query) === '') {
            return null;
        }

        try {
            // `noLocation` keeps the parse cheap — we only read operation kinds,
            // never report positions from this AST.
            $document = Parser::parse($query, ['noLocation' => true]);
        } catch (\Throwable) {
            // Malformed document → "not a subscription"; the one-shot branch
            // re-parses and surfaces the parse error normally.
            return null;
        }

        /** @var list<OperationDefinitionNode> $operations */
        $operations = [];
        foreach ($document->definitions as $definition) {
            if ($definition instanceof OperationDefinitionNode) {
                $operations[] = $definition;
            }
        }

        if ($operations === []) {
            return null;
        }

        if ($operationName !== null && $operationName !== '') {
            foreach ($operations as $operation) {
                if (($operation->name?->value) === $operationName) {
                    return $operation->operation;
                }
            }

            // A named operation was requested but no operation matches it. The
            // executor will reject this; treat it as "not a subscription" and
            // let the one-shot branch produce the canonical error.
            return null;
        }

        // A document with MULTIPLE operations REQUIRES an operationName; without
        // one, execution is ambiguous and webonyx rejects it. Returning the
        // first kind would route an ambiguous document into the subscription/SSE
        // fork — opening a held stream for a request that is really a validation
        // error. Stay undetermined so the one-shot branch surfaces the canonical
        // "must provide operation name" error.
        if (count($operations) > 1) {
            return null;
        }

        return $operations[0]->operation;
    }

    /**
     * Convenience predicate for the dual-mode fork: is the (resolved) operation
     * a `subscription`?
     */
    public function isSubscription(string $query, ?string $operationName = null): bool
    {
        return $this->resolveOperationType($query, $operationName) === 'subscription';
    }

    /**
     * The root field names selected by the document's `subscription` operation
     * (honouring `operationName` exactly like {@see resolveOperationType()}).
     *
     * This is the PHASE 4 bridge from the document to the schema-side
     * `#[ExposeAsGraphql(watchScopes:)]` declaration: each returned name is
     * matched against the registry's subscription operations to union the
     * resource scopes the held-open stream must watch. The GraphQL spec allows
     * exactly one root field on a subscription operation, but the collection is
     * defensive — every plain field at the root is reported, while fragment
     * spreads / inline fragments (invalid at a subscription root) are ignored
     * rather than chased. Same failure posture as the resolver above: malformed
     * or non-subscription documents yield `[]`.
     *
     * @return list<string>
     */
    public function subscriptionRootFields(string $query, ?string $operationName = null): array
    {
        if (trim($query) === '') {
            return [];
        }

        try {
            $document = Parser::parse($query, ['noLocation' => true]);
        } catch (\Throwable) {
            return [];
        }

        /** @var list<OperationDefinitionNode> $operations */
        $operations = [];
        foreach ($document->definitions as $definition) {
            if ($definition instanceof OperationDefinitionNode) {
                $operations[] = $definition;
            }
        }

        $matched = null;
        if ($operationName !== null && $operationName !== '') {
            foreach ($operations as $operation) {
                if (($operation->name?->value) === $operationName) {
                    $matched = $operation;
                    break;
                }
            }
        } elseif (count($operations) === 1) {
            // Only an unambiguous single-operation document may select the sole
            // operation. Multiple operations without operationName is ambiguous
            // (see resolveOperationType()) and must not register watch scopes.
            $matched = $operations[0];
        }

        if ($matched === null || $matched->operation !== 'subscription') {
            return [];
        }

        $fields = [];
        foreach ($matched->selectionSet->selections as $selection) {
            if ($selection instanceof FieldNode) {
                $fields[] = $selection->name->value;
            }
        }

        return $fields;
    }
}
