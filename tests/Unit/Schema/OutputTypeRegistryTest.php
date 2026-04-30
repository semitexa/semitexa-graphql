<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Schema;

use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use PHPUnit\Framework\TestCase;
use Semitexa\Graphql\Application\Service\Schema\OutputTypeRegistry;
use Semitexa\Graphql\Application\Service\Schema\ScalarTypeMapper;
use Semitexa\Graphql\Tests\Fixture\Runtime\RuntimeOutputFixture;

final class OutputTypeRegistryTest extends TestCase
{
    public function test_builds_object_type_with_one_field_per_public_property(): void
    {
        $registry = $this->newRegistry();
        $type = $registry->get(RuntimeOutputFixture::class);

        self::assertInstanceOf(ObjectType::class, $type);
        self::assertSame('RuntimeOutputFixture', $type->name);

        $fields = $type->getFields();
        self::assertArrayHasKey('id', $fields);
        self::assertArrayHasKey('name', $fields);
        self::assertArrayHasKey('count', $fields);
        self::assertArrayHasKey('enabled', $fields);
        self::assertArrayHasKey('score', $fields);
    }

    public function test_id_field_is_non_null_id(): void
    {
        $type = $this->newRegistry()->get(RuntimeOutputFixture::class);
        $idField = $type->getField('id');

        self::assertInstanceOf(NonNull::class, $idField->getType());
        self::assertSame(Type::id(), $idField->getType()->getWrappedType());
    }

    public function test_nullable_property_yields_nullable_field(): void
    {
        $type = $this->newRegistry()->get(RuntimeOutputFixture::class);
        $scoreField = $type->getField('score');

        self::assertNotInstanceOf(NonNull::class, $scoreField->getType());
        self::assertSame(Type::float(), $scoreField->getType());
    }

    public function test_caches_object_type_per_class(): void
    {
        $registry = $this->newRegistry();

        self::assertSame(
            $registry->get(RuntimeOutputFixture::class),
            $registry->get(RuntimeOutputFixture::class)
        );
    }

    public function test_field_resolver_reads_array_keys_and_object_properties(): void
    {
        $registry = $this->newRegistry();
        $type = $registry->get(RuntimeOutputFixture::class);
        $idField = $type->getField('id');
        $resolver = $idField->resolveFn;

        self::assertNotNull($resolver);
        self::assertSame('arr-id', ($resolver)(['id' => 'arr-id'], [], null, $this->info()));

        $object = new RuntimeOutputFixture(id: 'obj-id', name: 'n', count: 1, enabled: true);
        self::assertSame('obj-id', ($resolver)($object, [], null, $this->info()));
    }

    private function newRegistry(): OutputTypeRegistry
    {
        $registry = new OutputTypeRegistry();
        $registry->setScalarsForTest(new ScalarTypeMapper());
        return $registry;
    }

    private function info(): \GraphQL\Type\Definition\ResolveInfo
    {
        // Use Reflection to construct a minimal ResolveInfo without booting a schema —
        // the field resolvers in OutputTypeRegistry don't read it.
        $ref = new \ReflectionClass(\GraphQL\Type\Definition\ResolveInfo::class);
        return $ref->newInstanceWithoutConstructor();
    }
}
