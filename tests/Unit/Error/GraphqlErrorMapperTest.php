<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Error;

use GraphQL\Error\Error;
use GraphQL\Error\SyntaxError;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Exception\AccessDeniedException;
use Semitexa\Core\Exception\AuthenticationException;
use Semitexa\Core\Exception\NotFoundException;
use Semitexa\Core\Exception\ValidationException;
use Semitexa\Graphql\Pipeline\GraphqlErrorMapper;

final class GraphqlErrorMapperTest extends TestCase
{
    public function test_maps_validation_exception_to_VALIDATION_FAILED_with_field_errors(): void
    {
        $mapper = new GraphqlErrorMapper();
        $error = new Error(
            message: 'wrap',
            previous: new ValidationException(['title' => ['title required']]),
        );

        $shape = $mapper->mapError($error);

        self::assertSame('VALIDATION_FAILED', $shape['extensions']['code']);
        self::assertSame(422, $shape['extensions']['http_status']);
        self::assertSame(['title' => ['title required']], $shape['extensions']['errors']);
    }

    public function test_maps_not_found_exception(): void
    {
        $mapper = new GraphqlErrorMapper();
        $error = new Error(
            message: 'wrap',
            previous: new NotFoundException('Article', 'x'),
        );

        $shape = $mapper->mapError($error);

        self::assertSame('NOT_FOUND', $shape['extensions']['code']);
        self::assertSame(404, $shape['extensions']['http_status']);
        self::assertSame('Article #x not found.', $shape['message']);
    }

    public function test_maps_access_denied(): void
    {
        $shape = (new GraphqlErrorMapper())->mapError(
            new Error('wrap', previous: new AccessDeniedException('nope'))
        );

        self::assertSame('FORBIDDEN', $shape['extensions']['code']);
    }

    public function test_maps_authentication_required(): void
    {
        $shape = (new GraphqlErrorMapper())->mapError(
            new Error('wrap', previous: new AuthenticationException('login required'))
        );

        self::assertSame('UNAUTHENTICATED', $shape['extensions']['code']);
    }

    public function test_unknown_throwable_becomes_internal_server_error_without_leaking_message(): void
    {
        $shape = (new GraphqlErrorMapper())->mapError(
            new Error('wrap', previous: new \RuntimeException('SECRET internal detail'))
        );

        self::assertSame('INTERNAL_SERVER_ERROR', $shape['extensions']['code']);
        self::assertSame('Internal server error.', $shape['message']);
        self::assertStringNotContainsString('SECRET', $shape['message']);
    }

    public function test_graphql_syntax_error_keeps_its_message_and_gets_GRAPHQL_VALIDATION_code(): void
    {
        $error = new SyntaxError(new \GraphQL\Language\Source('bogus'), 0, 'unexpected token');

        $shape = (new GraphqlErrorMapper())->mapError($error);

        self::assertSame('GRAPHQL_VALIDATION', $shape['extensions']['code']);
        self::assertNotEmpty($shape['message']);
    }
}
