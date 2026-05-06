<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Exception\ValidationException;
use Semitexa\Graphql\Application\Payload\Request\GraphqlEndpointPayload;

/**
 * Setter-time validation contract for the POST /graphql endpoint.
 *
 * Validation is no longer post-hydration: the payload's setters reject
 * invalid values at assignment time by throwing
 * `Semitexa\Core\Exception\ValidationException`. The hydrator runs setters
 * directly, so an invalid value never reaches the handler.
 *
 * The exception's error context is `{ errors: { field: [messages] } }` and
 * the framework converts it to a 422 (HTTP path) or `VALIDATION_FAILED`
 * GraphQL error (GraphQL transport path).
 */
final class EndpointPayloadValidationTest extends TestCase
{
    public function test_setQuery_rejects_empty_string(): void
    {
        $payload = new GraphqlEndpointPayload();

        try {
            $payload->setQuery('');
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(
                ['errors' => ['query' => ['GraphQL `query` field is required and must be a non-empty string.']]],
                $e->getErrorContext(),
            );
            self::assertSame(422, $e->getStatusCode()->value);
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function whitespaceOnlyQueries(): iterable
    {
        yield 'single space'    => [' '];
        yield 'tabs'            => ["\t\t"];
        yield 'newlines'        => ["\n\n"];
        yield 'mixed whitespace' => [" \t\n  "];
    }

    /**
     * @dataProvider whitespaceOnlyQueries
     */
    public function test_setQuery_rejects_whitespace_only_strings(string $query): void
    {
        $payload = new GraphqlEndpointPayload();

        $this->expectException(ValidationException::class);
        $payload->setQuery($query);
    }

    public function test_setQuery_accepts_non_blank_string(): void
    {
        $payload = new GraphqlEndpointPayload();

        $payload->setQuery('query Q { articles { id } }');

        self::assertSame('query Q { articles { id } }', $payload->getQuery());
    }

    public function test_setQuery_trims_surrounding_whitespace(): void
    {
        // NotBlankValidationTrait::requireNotBlank trims its return value; the
        // stored query is the trimmed form, which is semantically equivalent
        // for the GraphQL parser and consistent with how every other
        // not-blank setter in the framework normalises its input.
        $payload = new GraphqlEndpointPayload();

        $payload->setQuery("  \nquery Q { articles { id } }\n  ");

        self::assertSame('query Q { articles { id } }', $payload->getQuery());
    }

    public function test_no_legacy_validate_method_remains_on_the_payload(): void
    {
        // Architectural pin: the post-hydration validate() / ValidatablePayload
        // path was retired in favour of setter-time validation. If a regression
        // re-introduces it, this test fails immediately.
        $reflection = new \ReflectionClass(GraphqlEndpointPayload::class);

        self::assertFalse(
            $reflection->hasMethod('validate'),
            'GraphqlEndpointPayload must not declare a legacy validate() method.',
        );
        self::assertSame(
            [],
            $reflection->getInterfaceNames(),
            'GraphqlEndpointPayload must not implement ValidatablePayload (or any other interface).',
        );
    }
}
