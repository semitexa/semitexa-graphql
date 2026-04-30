<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Schema;

use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\Type;
use PHPUnit\Framework\TestCase;
use Semitexa\Graphql\Application\Service\Schema\PayloadArgumentBuilder;
use Semitexa\Graphql\Application\Service\Schema\ScalarTypeMapper;
use Semitexa\Graphql\Tests\Fixture\Runtime\RuntimePayloadFixture;

final class PayloadArgumentBuilderTest extends TestCase
{
    public function test_builds_args_from_setter_signatures(): void
    {
        $builder = new PayloadArgumentBuilder();
        $builder->setScalarsForTest(new ScalarTypeMapper());

        $args = $builder->buildFor(RuntimePayloadFixture::class);

        self::assertArrayHasKey('id', $args);
        self::assertArrayHasKey('limit', $args);
    }

    public function test_id_arg_is_non_null(): void
    {
        $builder = new PayloadArgumentBuilder();
        $builder->setScalarsForTest(new ScalarTypeMapper());

        $args = $builder->buildFor(RuntimePayloadFixture::class);

        self::assertInstanceOf(NonNull::class, $args['id']['type']);
        self::assertSame(Type::id(), $args['id']['type']->getWrappedType());
    }

    public function test_non_id_args_are_nullable_scalars(): void
    {
        $builder = new PayloadArgumentBuilder();
        $builder->setScalarsForTest(new ScalarTypeMapper());

        $args = $builder->buildFor(RuntimePayloadFixture::class);

        self::assertNotInstanceOf(NonNull::class, $args['limit']['type']);
        self::assertSame(Type::int(), $args['limit']['type']);
    }
}
