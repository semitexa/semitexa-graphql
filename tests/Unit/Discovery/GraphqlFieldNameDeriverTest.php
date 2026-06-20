<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Discovery;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Semitexa\Graphql\Discovery\GraphqlFieldNameDeriver;

final class GraphqlFieldNameDeriverTest extends TestCase
{
    /**
     * @dataProvider derivableNames
     */
    public function test_derives_field_name_from_payload_class(string $class, string $expected): void
    {
        self::assertSame($expected, GraphqlFieldNameDeriver::derive($class));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function derivableNames(): iterable
    {
        // The four real Playground payloads that now carry a bare #[ExposeAsGraphql].
        yield 'query byId'        => ['App\\Foo\\ArticleByIdQueryPayload', 'articleById'];
        yield 'create mutation'   => ['CreateArticleMutationPayload', 'createArticle'];
        yield 'delete mutation'   => ['DeleteArticleMutationPayload', 'deleteArticle'];
        yield 'update mutation'   => ['UpdateArticleMutationPayload', 'updateArticle'];

        // Conventions exercised in isolation.
        yield 'subscription token' => ['ThingChangesSubscriptionPayload', 'thingChanges'];
        yield 'no root-type token' => ['WidgetPayload', 'widget'];
        yield 'deeply namespaced'  => ['Some\\Deep\\Ns\\ProductBySlugQueryPayload', 'productBySlug'];
    }

    /**
     * @dataProvider degenerateNames
     */
    public function test_rejects_names_that_collapse_to_empty(string $class): void
    {
        $this->expectException(InvalidArgumentException::class);
        GraphqlFieldNameDeriver::derive($class);
    }

    /** @return iterable<string, array{0: string}> */
    public static function degenerateNames(): iterable
    {
        yield 'bare Payload'        => ['Payload'];
        yield 'kind-only query'     => ['QueryPayload'];
        yield 'kind-only mutation'  => ['MutationPayload'];
        yield 'empty string'        => [''];
    }
}
