<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Domain\Exception;

/**
 * Thrown when a GraphQL result does not hold what a reader asked it for.
 *
 * Deliberately not a domain exception that maps to a 4xx: this describes a
 * caller reading a result that the schema and the query between them never
 * promised — a field name that is not in the selection set, a list read as an
 * object, a value read after execution produced no data at all. That is a
 * defect on our side of the wire, and blaming the client for it would be wrong.
 *
 * The message always names the path and what was actually there, because the
 * failure this replaces was `Cannot access offset 'id' on mixed` several frames
 * deep, or worse, a null that quietly compared equal to an expectation.
 */
final class UnexpectedGraphqlResultException extends \InvalidArgumentException
{
    public static function noData(string $path): self
    {
        return new self(sprintf(
            'Cannot read "%s": the result carries no data. Execution failed before a value '
            . 'existed — read errors() to find out why.',
            $path,
        ));
    }

    public static function missing(string $path, string $atSegment, mixed $container): self
    {
        return new self(sprintf(
            'Cannot read "%s": nothing at "%s". %s',
            $path,
            $atSegment,
            self::describeContainer($container),
        ));
    }

    public static function notTraversable(string $path, string $atSegment, mixed $actual): self
    {
        return new self(sprintf(
            'Cannot read "%s": "%s" is %s, so nothing can be read out of it.',
            $path,
            $atSegment,
            self::describe($actual),
        ));
    }

    public static function wrongType(string $path, string $expected, mixed $actual): self
    {
        return new self(sprintf(
            'GraphQL result at "%s" must be %s, got %s.',
            $path,
            $expected,
            self::describe($actual),
        ));
    }

    public static function noErrorAt(int $index, int $count): self
    {
        return new self(sprintf(
            'The result has no error at index %d (%d error(s) present).',
            $index,
            $count,
        ));
    }

    private static function describeContainer(mixed $container): string
    {
        if (!is_array($container)) {
            return 'It is ' . self::describe($container) . '.';
        }

        $keys = array_keys($container);

        return $keys === []
            ? 'It is empty.'
            : 'Present: ' . implode(', ', array_map(static fn (mixed $k): string => (string) $k, $keys)) . '.';
    }

    private static function describe(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_array($value)) {
            return array_is_list($value) ? 'a list of ' . count($value) : 'an object';
        }

        return get_debug_type($value);
    }
}
