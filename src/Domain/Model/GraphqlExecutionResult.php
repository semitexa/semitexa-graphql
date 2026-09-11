<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Domain\Model;

use Semitexa\Graphql\Domain\Exception\UnexpectedGraphqlResultException;

/**
 * GraphQL response envelope, framework-agnostic.
 *
 * Mirrors the GraphQL-over-HTTP response shape ({"data": ..., "errors": [...],
 * "extensions": ...}) without depending on webonyx types. The HTTP endpoint
 * (and any future transport) can serialize this directly to JSON.
 *
 * `data` is `null` only when execution could not produce a value at all
 * (parse / validation failure). Partial results — `data` set with some
 * `errors` — are valid GraphQL behavior.
 *
 * **Reading it.** `data` is `array<string, mixed>` and cannot be anything else:
 * a GraphQL response is whatever shape the query asked for, so the type ends at
 * the first key. Every caller then descended into `mixed` — `$result->data['articles'][0]['id']`
 * — and static analysis could say nothing about any of it, which is where 73
 * offset-on-mixed errors in the Playground suite came from. Not 73 problems:
 * one shape with no reader, multiplied by every place that read it.
 *
 * So the accessors below take a dotted path and END in a type: `string()`,
 * `int()`, `bool()`, `map()`, `list()`. They THROW rather than return null,
 * naming the path and what was actually there — because the failure they
 * replace was either an offset-on-mixed several frames deep, or worse, a null
 * that quietly compared equal to what the test expected.
 *
 * Numeric path segments index a list: `articles.0.id`. That is the same syntax
 * a GraphQL error's `path` uses, so a failing assertion and the error beside it
 * read alike.
 */
final readonly class GraphqlExecutionResult
{
    /**
     * @param array<string, mixed>|null $data
     * @param list<array<string, mixed>> $errors
     * @param array<string, mixed>      $extensions
     */
    public function __construct(
        public ?array $data,
        public array $errors,
        public array $extensions = [],
    ) {}

    /** Whether `$path` resolves to anything at all — including an explicit null. */
    public function has(string $path): bool
    {
        try {
            $this->read($path);
        } catch (UnexpectedGraphqlResultException) {
            return false;
        }

        return true;
    }

    /**
     * True when the field is present AND null — which GraphQL uses to mean
     * "this one failed", and which `has()` deliberately does not conflate with
     * absence.
     */
    public function isNull(string $path): bool
    {
        return $this->has($path) && $this->read($path) === null;
    }

    public function string(string $path): string
    {
        $value = $this->read($path);

        return is_string($value)
            ? $value
            : throw UnexpectedGraphqlResultException::wrongType($path, 'a string', $value);
    }

    public function int(string $path): int
    {
        $value = $this->read($path);

        return is_int($value)
            ? $value
            : throw UnexpectedGraphqlResultException::wrongType($path, 'an int', $value);
    }

    public function bool(string $path): bool
    {
        $value = $this->read($path);

        return is_bool($value)
            ? $value
            : throw UnexpectedGraphqlResultException::wrongType($path, 'a bool', $value);
    }

    /**
     * An object field — a selection set's worth of keys.
     *
     * @return array<string, mixed>
     */
    public function map(string $path): array
    {
        $value = $this->read($path);

        if (!is_array($value) || array_is_list($value) && $value !== []) {
            throw UnexpectedGraphqlResultException::wrongType($path, 'an object', $value);
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * A list field whose members are objects — what a selection set over a
     * collection returns.
     *
     * Each member is checked, not just the outer list: `{"articles":["x"]}`
     * would otherwise parse and hand a string back through an array return
     * type, which is a TypeError deep in the caller instead of an error naming
     * the real problem here.
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $path): array
    {
        $value = $this->read($path);

        if (!is_array($value) || !array_is_list($value)) {
            throw UnexpectedGraphqlResultException::wrongType($path, 'a list', $value);
        }

        foreach ($value as $index => $item) {
            if (!is_array($item)) {
                throw UnexpectedGraphqlResultException::wrongType($path . '.' . $index, 'an object', $item);
            }
        }

        /** @var list<array<string, mixed>> $value */
        return $value;
    }

    /**
     * The machine-readable code of one error, which is what assertions are
     * actually about — the message is prose and changes.
     */
    public function errorCode(int $index = 0): string
    {
        $error = $this->error($index);
        $extensions = $error['extensions'] ?? null;

        if (!is_array($extensions) || !is_string($extensions['code'] ?? null)) {
            throw UnexpectedGraphqlResultException::wrongType(
                'errors.' . $index . '.extensions.code',
                'a string',
                is_array($extensions) ? ($extensions['code'] ?? null) : $extensions,
            );
        }

        return $extensions['code'];
    }

    /** @return array<string, mixed> */
    public function error(int $index = 0): array
    {
        return $this->errors[$index]
            ?? throw UnexpectedGraphqlResultException::noErrorAt($index, count($this->errors));
    }

    /**
     * Walk a dotted path into `data`.
     *
     * @throws UnexpectedGraphqlResultException when the path does not exist or
     *         runs into a value that cannot be descended
     */
    private function read(string $path): mixed
    {
        if ($this->data === null) {
            throw UnexpectedGraphqlResultException::noData($path);
        }

        $cursor = $this->data;
        $walked = '';

        foreach (explode('.', $path) as $segment) {
            $walked = $walked === '' ? $segment : $walked . '.' . $segment;

            if (!is_array($cursor)) {
                throw UnexpectedGraphqlResultException::notTraversable($path, $walked, $cursor);
            }

            $key = ctype_digit($segment) ? (int) $segment : $segment;

            if (!array_key_exists($key, $cursor)) {
                throw UnexpectedGraphqlResultException::missing($path, $walked, $cursor);
            }

            $cursor = $cursor[$key];
        }

        return $cursor;
    }

    /** @return array{data?: array<string, mixed>|null, errors?: list<array<string, mixed>>, extensions?: array<string, mixed>} */
    public function toArray(): array
    {
        $out = [];
        if ($this->errors !== []) {
            $out['errors'] = $this->errors;
        }
        // GraphQL spec: include `data` whenever execution actually ran. If
        // parse/validation failed, $data is null and we omit it; if the
        // operation ran but returned a null/empty payload, we include it.
        if ($this->data !== null || $this->errors === []) {
            $out['data'] = $this->data;
        }
        if ($this->extensions !== []) {
            $out['extensions'] = $this->extensions;
        }
        return $out;
    }
}
