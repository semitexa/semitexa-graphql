<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Support;

use Semitexa\Core\Contract\RouteInspectionRegistryInterface;
use Semitexa\Core\Discovery\ResolvedRouteMetadata;

/**
 * Test-only registry that returns a fixed list of ResolvedRouteMetadata.
 *
 * Lets registry tests construct curated route fixtures without booting the
 * framework's discovery pipeline.
 */
final class StubRouteInspectionRegistry implements RouteInspectionRegistryInterface
{
    /** @param list<ResolvedRouteMetadata> $routes */
    public function __construct(private array $routes = []) {}

    public function all(): array
    {
        return $this->routes;
    }

    public function findByPath(string $path, string $method): ?ResolvedRouteMetadata
    {
        foreach ($this->routes as $route) {
            if ($route->path === $path && in_array(strtoupper($method), array_map('strtoupper', $route->methods), true)) {
                return $route;
            }
        }
        return null;
    }

    public function findByName(string $name): ?ResolvedRouteMetadata
    {
        foreach ($this->routes as $route) {
            if ($route->name === $name) {
                return $route;
            }
        }
        return null;
    }

    /**
     * Convenience builder for tests: produce a ResolvedRouteMetadata with sensible defaults.
     *
     * @param list<string> $methods
     * @param list<array<string,mixed>> $handlers
     */
    public static function metadata(
        string $payloadClass,
        string $name = 'test.route',
        string $path = '/test',
        array $methods = ['GET'],
        string $responseClass = 'Resource',
        array $handlers = [],
    ): ResolvedRouteMetadata {
        return new ResolvedRouteMetadata(
            path: $path,
            name: $name,
            methods: $methods,
            requestClass: $payloadClass,
            responseClass: $responseClass,
            produces: ['application/json'],
            consumes: null,
            handlers: $handlers,
            requirements: [],
            extensions: [],
        );
    }
}
