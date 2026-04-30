<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Domain\Contract;

/**
 * Extracts the GraphQL-shaped value from a Semitexa Resource DTO.
 *
 * The Resource produced by a Handler is the shared shape used by every
 * Semitexa transport (HTTP renderer, in-process tests, GraphQL). Each
 * transport projects it differently. This contract is GraphQL's projection
 * step: produce the value that webonyx will then walk against the field's
 * declared output type.
 *
 * Returning `null` is valid (e.g. for delete-style mutations whose output
 * type is nullable).
 *
 * Returning `array<string, mixed>` projects a single object (one Article).
 * Returning `list<array<string, mixed>>` projects a list (many Articles).
 * Scalars are also valid where the schema's output type is a scalar.
 */
interface ResourceSerializerInterface
{
    public function serialize(object $resource): mixed;
}
