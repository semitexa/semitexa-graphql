<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Semitexa\Graphql\Application\Service\Runtime\GraphqlOperationTypeResolver;

/**
 * The pre-execution operation-type detection seam (PHASE 1).
 *
 * Pure AST parsing — no schema, no executor, no Swoole. Proves the seam keys
 * the dual-mode fork on the document's operation kind and degrades safely on
 * malformed / ambiguous input (so the handler can fall through to one-shot).
 */
final class GraphqlOperationTypeResolverTest extends TestCase
{
    private GraphqlOperationTypeResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new GraphqlOperationTypeResolver();
    }

    public function test_detects_subscription(): void
    {
        self::assertSame('subscription', $this->resolver->resolveOperationType('subscription { anything }'));
        self::assertTrue($this->resolver->isSubscription('subscription { anything }'));
    }

    public function test_detects_query_and_mutation_are_not_subscriptions(): void
    {
        self::assertSame('query', $this->resolver->resolveOperationType('query { articles { id } }'));
        self::assertSame('mutation', $this->resolver->resolveOperationType('mutation { createArticle { id } }'));
        self::assertFalse($this->resolver->isSubscription('query { articles { id } }'));
        self::assertFalse($this->resolver->isSubscription('mutation { createArticle { id } }'));
    }

    public function test_shorthand_anonymous_operation_is_a_query(): void
    {
        // A bare selection set with no operation keyword is a query per the spec.
        self::assertSame('query', $this->resolver->resolveOperationType('{ articles { id } }'));
        self::assertFalse($this->resolver->isSubscription('{ articles { id } }'));
    }

    public function test_respects_operation_name_across_multiple_operations(): void
    {
        $doc = 'query Q { articles { id } } subscription S { articleChanges { id } }';

        self::assertSame('query', $this->resolver->resolveOperationType($doc, 'Q'));
        self::assertSame('subscription', $this->resolver->resolveOperationType($doc, 'S'));
        self::assertTrue($this->resolver->isSubscription($doc, 'S'));
        self::assertFalse($this->resolver->isSubscription($doc, 'Q'));
    }

    public function test_unknown_operation_name_is_not_a_subscription(): void
    {
        $doc = 'subscription S { articleChanges { id } }';

        self::assertNull($this->resolver->resolveOperationType($doc, 'DoesNotExist'));
        self::assertFalse($this->resolver->isSubscription($doc, 'DoesNotExist'));
    }

    public function test_malformed_document_is_not_a_subscription(): void
    {
        // No throw, no 500 — the one-shot branch re-parses and surfaces the error.
        self::assertNull($this->resolver->resolveOperationType('subscription { unclosed'));
        self::assertFalse($this->resolver->isSubscription('subscription { unclosed'));
    }

    public function test_empty_query_is_not_a_subscription(): void
    {
        self::assertNull($this->resolver->resolveOperationType(''));
        self::assertNull($this->resolver->resolveOperationType('   '));
        self::assertFalse($this->resolver->isSubscription(''));
    }

    // ---- PHASE 4: subscription root-field extraction ------------------------

    public function test_subscription_root_fields_for_single_field(): void
    {
        self::assertSame(
            ['articleChanges'],
            $this->resolver->subscriptionRootFields('subscription { articleChanges { id title } }'),
        );
    }

    public function test_subscription_root_fields_honours_operation_name(): void
    {
        $doc = 'query Q { articles { id } } subscription S { articleChanges { id } }';

        self::assertSame(['articleChanges'], $this->resolver->subscriptionRootFields($doc, 'S'));
        // The named operation is a query, not a subscription → no fields.
        self::assertSame([], $this->resolver->subscriptionRootFields($doc, 'Q'));
    }

    public function test_subscription_root_fields_reports_every_root_field_defensively(): void
    {
        self::assertSame(
            ['articleChanges', 'pingChanges'],
            $this->resolver->subscriptionRootFields('subscription { articleChanges { id } pingChanges { id } }'),
        );
    }

    public function test_subscription_root_fields_empty_for_non_subscription_malformed_or_blank(): void
    {
        self::assertSame([], $this->resolver->subscriptionRootFields('query { articles { id } }'));
        self::assertSame([], $this->resolver->subscriptionRootFields('subscription { unclosed'));
        self::assertSame([], $this->resolver->subscriptionRootFields(''));
        self::assertSame([], $this->resolver->subscriptionRootFields('subscription S { articleChanges { id } }', 'Missing'));
    }
}
