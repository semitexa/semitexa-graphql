<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Schema;

use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Metadata\ResourceFieldKind;
use Semitexa\Core\Resource\Metadata\ResourceFieldMetadata;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceObjectMetadata;
use Semitexa\Graphql\Application\Service\Schema\OutputTypeRegistry;
use Semitexa\Graphql\Application\Service\Schema\ScalarTypeMapper;
use Semitexa\Graphql\Tests\Fixture\Schema\RefIdResourceFixture;
use Semitexa\Graphql\Tests\Fixture\Schema\ScalarArticleResourceFixture;

/**
 * Proves the single-source-of-truth path: a GraphQL ObjectType derived from a
 * `#[ResourceObject]`'s canonical metadata — the same contract OpenAPI reads —
 * without any `#[ExposeAsGraphql(output: ...)]` DTO.
 */
final class OutputTypeRegistryResourceTest extends TestCase
{
    public function test_builds_object_type_from_resource_metadata(): void
    {
        $type = $this->resourceType();

        self::assertInstanceOf(ObjectType::class, $type);
        // Type name is the class basename — the SAME rule OpenAPI's component
        // naming uses, so one #[ResourceObject] gets one name on both surfaces.
        self::assertSame('ScalarArticleResourceFixture', $type->name);

        $fields = $type->getFields();
        self::assertArrayHasKey('id', $fields);
        self::assertArrayHasKey('title', $fields);
        self::assertArrayHasKey('views', $fields);
        self::assertArrayHasKey('published', $fields);
    }

    public function test_id_field_is_non_null_id(): void
    {
        $idField = $this->resourceType()->getField('id');

        self::assertInstanceOf(NonNull::class, $idField->getType());
        self::assertSame(Type::id(), $idField->getType()->getWrappedType());
    }

    public function test_nullable_scalar_yields_nullable_field(): void
    {
        $viewsField = $this->resourceType()->getField('views');

        self::assertNotInstanceOf(NonNull::class, $viewsField->getType());
        self::assertSame(Type::int(), $viewsField->getType());
    }

    public function test_non_null_bool_is_wrapped(): void
    {
        $publishedField = $this->resourceType()->getField('published');

        self::assertInstanceOf(NonNull::class, $publishedField->getType());
        self::assertSame(Type::boolean(), $publishedField->getType()->getWrappedType());
    }

    public function test_field_description_propagates_from_resource_field(): void
    {
        self::assertSame('Article title.', $this->resourceType()->getField('title')->description);
    }

    public function test_field_resolver_reads_serialized_array_keys(): void
    {
        $resolver = $this->resourceType()->getField('title')->resolveFn;

        self::assertNotNull($resolver);
        self::assertSame('Hello', ($resolver)(['title' => 'Hello'], [], null, $this->info()));
    }

    public function test_caches_object_type_per_resource_class(): void
    {
        $registry = $this->newRegistry();
        $meta = (new ResourceMetadataExtractor())->extract(ScalarArticleResourceFixture::class);

        self::assertSame($registry->forResource($meta), $registry->forResource($meta));
    }

    public function test_declared_id_field_maps_to_ID_even_with_a_nonstandard_name(): void
    {
        $meta = (new ResourceMetadataExtractor())->extract(RefIdResourceFixture::class);
        $type = $this->newRegistry()->forResource($meta);

        // `ref` is the #[ResourceId] — must be ID!, not String!, despite the name
        // not being id/uuid/slug.
        $refField = $type->getField('ref');
        self::assertInstanceOf(NonNull::class, $refField->getType());
        self::assertSame(Type::id(), $refField->getType()->getWrappedType());

        // A normal scalar field stays String.
        self::assertSame(Type::string(), $type->getField('label')->getType()->getWrappedType());
    }

    public function test_resource_with_only_relation_fields_returns_null_for_json_fallback(): void
    {
        // No scalar/id fields, only a relation — forResource() must NOT build a
        // fieldless ObjectType (webonyx would fatal the whole schema); it returns
        // null so the caller degrades that one field to the Json scalar.
        $meta = new ResourceObjectMetadata(
            class: ScalarArticleResourceFixture::class,
            type: 'graphql.test.relations_only',
            idField: null,
            fields: [
                'author' => new ResourceFieldMetadata(
                    name: 'author',
                    kind: ResourceFieldKind::RefOne,
                    nullable: true,
                    target: ScalarArticleResourceFixture::class,
                ),
            ],
        );

        self::assertNull($this->newRegistry()->forResource($meta));
    }

    public function test_two_resources_with_the_same_class_basename_fail_fast(): void
    {
        $registry = $this->newRegistry();
        $extractor = new ResourceMetadataExtractor();

        $registry->forResource($extractor->extract(\Semitexa\Graphql\Tests\Fixture\Schema\CollideA\Widget::class));

        // Second resource shares the basename "Widget" → must fail with an
        // actionable message rather than webonyx's cryptic "unique named types".
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate GraphQL type name "Widget"');
        $registry->forResource($extractor->extract(\Semitexa\Graphql\Tests\Fixture\Schema\CollideB\Widget::class));
    }

    private function resourceType(): ObjectType
    {
        $meta = (new ResourceMetadataExtractor())->extract(ScalarArticleResourceFixture::class);

        return $this->newRegistry()->forResource($meta);
    }

    private function newRegistry(): OutputTypeRegistry
    {
        $registry = new OutputTypeRegistry();
        $registry->setScalarsForTest(new ScalarTypeMapper());

        return $registry;
    }

    private function info(): \GraphQL\Type\Definition\ResolveInfo
    {
        $ref = new \ReflectionClass(\GraphQL\Type\Definition\ResolveInfo::class);

        return $ref->newInstanceWithoutConstructor();
    }
}
