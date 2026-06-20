<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Schema;

use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\UnionType;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Resource\Metadata\ResourceFieldKind;
use Semitexa\Core\Resource\Metadata\ResourceFieldMetadata;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\Metadata\ResourceObjectMetadata;
use Semitexa\Core\Resource\RelationResolverInterface;
use Semitexa\Core\Resource\RenderContext;
use Semitexa\Graphql\Application\Service\Runtime\LazyRelationResolver;
use Semitexa\Graphql\Application\Service\Schema\OutputTypeRegistry;
use Semitexa\Graphql\Application\Service\Schema\ScalarTypeMapper;
use Semitexa\Graphql\Tests\Fixture\Schema\CollideA\Widget;
use Semitexa\Graphql\Tests\Fixture\Schema\RefIdResourceFixture;
use Semitexa\Graphql\Tests\Fixture\Schema\ScalarArticleResourceFixture;

/**
 * Proves relation fields declared on a `#[ResourceObject]` (RefOne / RefMany /
 * Union) become traversable nested GraphQL object fields, derived from the
 * related Resource's own metadata — and that a cyclic relation does not recurse
 * forever (`tk-gql-nested-outputtype-relations`).
 */
final class OutputTypeRegistryNestedTest extends TestCase
{
    public function test_ref_one_becomes_a_nested_object_field(): void
    {
        $registry = $this->registryWith($this->scalarMeta(ScalarArticleResourceFixture::class));

        $parent = $registry->forResource($this->parentWith(
            new ResourceFieldMetadata(
                name: 'article',
                kind: ResourceFieldKind::RefOne,
                nullable: true,
                target: ScalarArticleResourceFixture::class,
                description: 'Linked article.',
            ),
        ));

        $articleField = $parent->getField('article');
        self::assertInstanceOf(ObjectType::class, $articleField->getType());
        self::assertSame('ScalarArticleResourceFixture', $articleField->getType()->name);
        self::assertSame('Linked article.', $articleField->description);
        // The nested type carries its own scalar fields.
        self::assertArrayHasKey('title', $articleField->getType()->getFields());
    }

    public function test_ref_many_becomes_a_list_of_non_null_nested_objects(): void
    {
        $registry = $this->registryWith($this->scalarMeta(ScalarArticleResourceFixture::class));

        $parent = $registry->forResource($this->parentWith(
            new ResourceFieldMetadata(
                name: 'tags',
                kind: ResourceFieldKind::RefMany,
                nullable: true,
                target: ScalarArticleResourceFixture::class,
                list: true,
            ),
        ));

        $listType = $parent->getField('tags')->getType();
        self::assertInstanceOf(ListOfType::class, $listType);
        $element = $listType->getWrappedType();
        self::assertInstanceOf(NonNull::class, $element);
        self::assertInstanceOf(ObjectType::class, $element->getWrappedType());
        self::assertSame('ScalarArticleResourceFixture', $element->getWrappedType()->name);
    }

    public function test_cyclic_relation_resolves_to_the_same_cached_instance(): void
    {
        $resources = new ResourceMetadataRegistry();
        // A (RefIdResourceFixture) → b → B (ScalarArticleResourceFixture) → a → A
        $metaA = new ResourceObjectMetadata(
            class: RefIdResourceFixture::class,
            type: 'graphql.test.cycle_a',
            idField: 'ref',
            fields: [
                new ResourceFieldMetadata(name: 'ref', kind: ResourceFieldKind::Scalar, nullable: false),
                new ResourceFieldMetadata(name: 'b', kind: ResourceFieldKind::RefOne, nullable: true, target: ScalarArticleResourceFixture::class),
            ],
        );
        $metaB = new ResourceObjectMetadata(
            class: ScalarArticleResourceFixture::class,
            type: 'graphql.test.cycle_b',
            idField: 'id',
            fields: [
                new ResourceFieldMetadata(name: 'id', kind: ResourceFieldKind::Scalar, nullable: false),
                new ResourceFieldMetadata(name: 'a', kind: ResourceFieldKind::RefOne, nullable: true, target: RefIdResourceFixture::class),
            ],
        );
        $resources->register($metaA);
        $resources->register($metaB);

        $registry = new OutputTypeRegistry();
        $registry->setScalarsForTest(new ScalarTypeMapper());
        $registry->setResourcesForTest($resources);

        $typeA = $registry->forResource($metaA);
        // Walking A → b → a must arrive back at the very same A instance, proving
        // the type was cached before its fields thunk ran (no infinite recursion).
        $typeB = $typeA->getField('b')->getType();
        self::assertInstanceOf(ObjectType::class, $typeB);
        $backToA = $typeB->getField('a')->getType();
        self::assertSame($typeA, $backToA);
    }

    public function test_union_field_becomes_a_union_type_discriminated_by_value(): void
    {
        $resources = new ResourceMetadataRegistry();
        $resources->register($this->scalarMeta(ScalarArticleResourceFixture::class));
        $resources->register($this->scalarMeta(RefIdResourceFixture::class));

        $registry = new OutputTypeRegistry();
        $registry->setScalarsForTest(new ScalarTypeMapper());
        $registry->setResourcesForTest($resources);

        // Parent class (Widget) is deliberately neither union member, so no cache
        // or type-name collision.
        $parent = $registry->forResource(new ResourceObjectMetadata(
            class: Widget::class,
            type: 'graphql.test.union_parent',
            idField: null,
            fields: [
                new ResourceFieldMetadata(
                    name: 'shape',
                    kind: ResourceFieldKind::Union,
                    nullable: true,
                    target: null,
                    unionTargets: [ScalarArticleResourceFixture::class, RefIdResourceFixture::class],
                    discriminator: 'kind',
                ),
            ],
        ));

        $union = $parent->getField('shape')->getType();
        self::assertInstanceOf(UnionType::class, $union);
        self::assertCount(2, $union->getTypes());

        // resolveType picks the concrete member by the discriminator value, which
        // may be the canonical resource type.
        $resolve = $union->config['resolveType'];
        $member = $resolve(['kind' => 'graphql.test.article'], null, $this->info());
        self::assertSame('ScalarArticleResourceFixture', $member->name);
    }

    public function test_relation_only_resource_is_traversable_when_registry_is_wired(): void
    {
        // The mirror of OutputTypeRegistryResourceTest's null-fallback case: with
        // the metadata registry wired AND a scalarful target, a relation-only
        // resource now yields a real ObjectType instead of degrading to Json.
        $registry = $this->registryWith($this->scalarMeta(ScalarArticleResourceFixture::class));

        $type = $registry->forResource(new ResourceObjectMetadata(
            class: Widget::class,
            type: 'graphql.test.relations_only',
            idField: null,
            fields: [
                new ResourceFieldMetadata(
                    name: 'author',
                    kind: ResourceFieldKind::RefOne,
                    nullable: true,
                    target: ScalarArticleResourceFixture::class,
                ),
            ],
        ));

        self::assertInstanceOf(ObjectType::class, $type);
        self::assertArrayHasKey('author', $type->getFields());
    }

    public function test_relation_resolver_prefers_eagerly_nested_data(): void
    {
        $registry = $this->registryWith($this->scalarMeta(ScalarArticleResourceFixture::class));
        $registry->setLazyRelationsForTest($this->throwingLazyResolver());

        $resolve = $registry->forResource($this->parentWith(
            new ResourceFieldMetadata(
                name: 'article',
                kind: ResourceFieldKind::RefOne,
                nullable: true,
                target: ScalarArticleResourceFixture::class,
                resolverClass: 'stub.resolver',
            ),
        ))->getField('article')->resolveFn;

        // The handler eagerly nested the value → the lazy resolver (which would
        // throw) is never consulted.
        $value = ($resolve)(['ref' => 'p1', 'article' => ['title' => 'Eager']], [], null, $this->info());
        self::assertSame(['title' => 'Eager'], $value);
    }

    public function test_relation_resolver_falls_back_to_resolve_with_resolver(): void
    {
        $registry = $this->registryWith($this->scalarMeta(ScalarArticleResourceFixture::class));

        $resolver = new class implements RelationResolverInterface {
            public function resolveBatch(array $parents, RenderContext $ctx): array
            {
                // Key by the parent's URN, exactly as the contract requires.
                return [$parents[0]->urn() => ['title' => 'Lazy for ' . $parents[0]->id]];
            }
        };
        $lazy = new LazyRelationResolver();
        $lazy->setContainerForTest($this->containerReturning('stub.resolver', $resolver));
        $registry->setLazyRelationsForTest($lazy);

        $resolve = $registry->forResource($this->parentWith(
            new ResourceFieldMetadata(
                name: 'article',
                kind: ResourceFieldKind::RefOne,
                nullable: true,
                target: ScalarArticleResourceFixture::class,
                resolverClass: 'stub.resolver',
            ),
        ))->getField('article')->resolveFn;

        // No eager 'article' key → the declared resolver loads it for this parent.
        $value = ($resolve)(['ref' => 'p1'], [], null, $this->info());
        self::assertSame(['title' => 'Lazy for p1'], $value);
    }

    private function throwingLazyResolver(): LazyRelationResolver
    {
        $resolver = new class implements RelationResolverInterface {
            public function resolveBatch(array $parents, RenderContext $ctx): array
            {
                throw new \RuntimeException('lazy resolver must not be called when data is eager');
            }
        };
        $lazy = new LazyRelationResolver();
        $lazy->setContainerForTest($this->containerReturning('stub.resolver', $resolver));

        return $lazy;
    }

    private function containerReturning(string $id, object $service): ContainerInterface
    {
        return new class ($id, $service) implements ContainerInterface {
            public function __construct(private string $id, private object $service)
            {
            }

            public function has(string $id): bool
            {
                return $id === $this->id;
            }

            public function get(string $id): mixed
            {
                return $this->service;
            }
        };
    }

    private function registryWith(ResourceObjectMetadata ...$targets): OutputTypeRegistry
    {
        $resources = new ResourceMetadataRegistry();
        foreach ($targets as $target) {
            $resources->register($target);
        }

        $registry = new OutputTypeRegistry();
        $registry->setScalarsForTest(new ScalarTypeMapper());
        $registry->setResourcesForTest($resources);

        return $registry;
    }

    private function scalarMeta(string $class): ResourceObjectMetadata
    {
        return (new ResourceMetadataExtractor())->extract($class);
    }

    private function parentWith(ResourceFieldMetadata $relation): ResourceObjectMetadata
    {
        return new ResourceObjectMetadata(
            class: RefIdResourceFixture::class,
            type: 'graphql.test.parent',
            idField: 'ref',
            fields: [
                new ResourceFieldMetadata(name: 'ref', kind: ResourceFieldKind::Scalar, nullable: false),
                new ResourceFieldMetadata(name: 'label', kind: ResourceFieldKind::Scalar, nullable: false),
                $relation,
            ],
        );
    }

    private function info(): ResolveInfo
    {
        return (new \ReflectionClass(ResolveInfo::class))->newInstanceWithoutConstructor();
    }
}
