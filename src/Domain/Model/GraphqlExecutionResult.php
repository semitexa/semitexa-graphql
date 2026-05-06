<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Domain\Model;

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
