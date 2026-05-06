<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Schema;

use GraphQL\Type\Definition\Type;
use PHPUnit\Framework\TestCase;
use Semitexa\Graphql\Application\Service\Schema\ScalarTypeMapper;

final class ScalarTypeMapperTest extends TestCase
{
    public function test_php_scalars_map_to_graphql_scalars(): void
    {
        $m = new ScalarTypeMapper();

        self::assertSame(Type::string(), $m->mapForArgument('string', 'title'));
        self::assertSame(Type::int(), $m->mapForArgument('int', 'limit'));
        self::assertSame(Type::float(), $m->mapForArgument('float', 'score'));
        self::assertSame(Type::boolean(), $m->mapForArgument('bool', 'published'));
    }

    public function test_id_named_args_map_to_id_regardless_of_php_type(): void
    {
        $m = new ScalarTypeMapper();

        self::assertSame(Type::id(), $m->mapForArgument('string', 'id'));
        self::assertSame(Type::id(), $m->mapForArgument('int', 'id'));
        self::assertSame(Type::id(), $m->mapForArgument('string', 'slug'));
        self::assertSame(Type::id(), $m->mapForArgument('string', 'uuid'));
    }

    public function test_unknown_php_types_return_null(): void
    {
        $m = new ScalarTypeMapper();

        self::assertNull($m->mapForArgument('DateTimeImmutable', 'when'));
        self::assertNull($m->mapForOutputField('SomeObject', 'whatever'));
    }

    public function test_output_fields_obey_same_id_naming(): void
    {
        $m = new ScalarTypeMapper();

        self::assertSame(Type::id(), $m->mapForOutputField('string', 'id'));
        self::assertSame(Type::string(), $m->mapForOutputField('string', 'title'));
    }
}
