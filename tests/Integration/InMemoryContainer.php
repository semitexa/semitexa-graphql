<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Integration;

use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Minimal PSR-11 container for integration tests.
 *
 * Returns the pre-supplied object for `get()`, reports correctly via
 * `has()`, and throws for unknown ids — matches the surface
 * `ContainerHandlerInvoker` actually uses.
 */
final class InMemoryContainer implements ContainerInterface
{
    /** @param array<string, object> $bindings */
    public function __construct(private readonly array $bindings) {}

    public function get(string $id): object
    {
        if (!array_key_exists($id, $this->bindings)) {
            throw new RuntimeException("No binding for {$id}.");
        }
        return $this->bindings[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->bindings);
    }
}
