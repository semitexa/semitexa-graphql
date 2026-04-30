<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture\Runtime;

/**
 * Output DTO used by Runtime tests. Mirrors the shape of a real Article
 * but with one of every scalar type so the `OutputTypeRegistry` and
 * `ScalarTypeMapper` mappings get full coverage.
 */
final readonly class RuntimeOutputFixture
{
    public function __construct(
        public string $id,
        public string $name,
        public int $count,
        public bool $enabled,
        public ?float $score = null,
    ) {}
}
