<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Discovery;

use PHPUnit\Framework\TestCase;
use Semitexa\Graphql\Discovery\ResolvedGraphqlOperation;

final class ResolvedGraphqlOperationTest extends TestCase
{
    public function test_isQuery_isMutation_reflect_root_type(): void
    {
        $query = $this->make(rootType: 'query');
        $mutation = $this->make(rootType: 'mutation');

        self::assertTrue($query->isQuery());
        self::assertFalse($query->isMutation());

        self::assertTrue($mutation->isMutation());
        self::assertFalse($mutation->isQuery());
    }

    public function test_dto_exposes_every_constructor_field_as_readonly_property(): void
    {
        $op = new ResolvedGraphqlOperation(
            field: 'articleById',
            rootType: 'query',
            payloadClass: 'Foo\\Payload',
            outputClass: 'Foo\\Output',
            routeName: 'foo.bar',
            path: '/foo/{id}',
            httpMethods: ['GET'],
            handlerClasses: ['Foo\\Handler'],
            responseClass: 'Foo\\Resource',
            description: 'fetch a foo',
        );

        self::assertSame('articleById', $op->field);
        self::assertSame('query', $op->rootType);
        self::assertSame('Foo\\Payload', $op->payloadClass);
        self::assertSame('Foo\\Output', $op->outputClass);
        self::assertSame('foo.bar', $op->routeName);
        self::assertSame('/foo/{id}', $op->path);
        self::assertSame(['GET'], $op->httpMethods);
        self::assertSame(['Foo\\Handler'], $op->handlerClasses);
        self::assertSame('Foo\\Resource', $op->responseClass);
        self::assertSame('fetch a foo', $op->description);
    }

    public function test_outputClass_is_nullable(): void
    {
        $op = $this->make(outputClass: null);
        self::assertNull($op->outputClass);
    }

    private function make(
        string $rootType = 'query',
        ?string $outputClass = 'Foo\\Output',
    ): ResolvedGraphqlOperation {
        return new ResolvedGraphqlOperation(
            field: 'foo',
            rootType: $rootType,
            payloadClass: 'Foo\\Payload',
            outputClass: $outputClass,
            routeName: 'foo',
            path: '/foo',
            httpMethods: ['GET'],
            handlerClasses: [],
            responseClass: 'Foo\\Resource',
            description: '',
        );
    }
}
