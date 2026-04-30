<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Semitexa\Core\Attribute\AsPayload;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Config\EnvValueResolver;
use Semitexa\Graphql\Application\Handler\PayloadHandler\GraphqlEndpointHandler;
use Semitexa\Graphql\Application\Payload\Request\GraphqlEndpointPayload;
use Semitexa\Graphql\Application\Resource\Response\GraphqlEndpointResource;

/**
 * Architecture guard: the POST /graphql HTTP route is owned by the
 * semitexa-graphql package, not by any application module.
 *
 * This test exists because the endpoint trio was previously misplaced in
 * the Playground demo module, which is reusable framework behaviour. The
 * test pins the route declaration to the package's canonical
 * Application/Payload/Request, Application/Handler/PayloadHandler and
 * Application/Resource/Response namespaces so a regression cannot quietly
 * move it back into a host application.
 */
final class EndpointOwnershipTest extends TestCase
{
    public function test_endpoint_payload_declares_post_graphql_route_in_package_namespace(): void
    {
        $reflection = new ReflectionClass(GraphqlEndpointPayload::class);

        self::assertSame(
            'Semitexa\\Graphql\\Application\\Payload\\Request',
            $reflection->getNamespaceName(),
            'POST /graphql owner must live under the package namespace, not in a host module.',
        );

        $attributes = $reflection->getAttributes(AsPayload::class);
        self::assertCount(1, $attributes, 'GraphqlEndpointPayload must declare exactly one #[AsPayload].');

        /** @var AsPayload $route */
        $route = $attributes[0]->newInstance();

        // The path is declared with Semitexa's env::VAR::default attribute
        // syntax so deployments can override the public route through .env
        // without touching package code. Discovery resolves it via
        // EnvValueResolver before the route is registered.
        self::assertSame('env::SEMITEXA_GRAPHQL_ROUTE_PATH::/graphql', $route->path);
        self::assertSame(['POST'], $route->methods);
        self::assertSame('graphql.endpoint', $route->name);
        self::assertSame(GraphqlEndpointResource::class, $route->responseWith);
    }

    public function test_route_path_defaults_to_graphql_when_env_var_unset(): void
    {
        $reflection = new ReflectionClass(GraphqlEndpointPayload::class);
        /** @var AsPayload $route */
        $route = $reflection->getAttributes(AsPayload::class)[0]->newInstance();

        $previous = getenv('SEMITEXA_GRAPHQL_ROUTE_PATH');
        putenv('SEMITEXA_GRAPHQL_ROUTE_PATH');
        try {
            self::assertSame(
                '/graphql',
                EnvValueResolver::resolve($route->path),
                'With SEMITEXA_GRAPHQL_ROUTE_PATH unset, the inline default /graphql must apply.',
            );
        } finally {
            if ($previous !== false) {
                putenv("SEMITEXA_GRAPHQL_ROUTE_PATH={$previous}");
            }
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function overridePathProvider(): iterable
    {
        yield 'rooted under /api'      => ['/api/graphql'];
        yield 'rooted under /internal' => ['/internal/graphql'];
        yield 'rooted under /admin'    => ['/admin/graphql'];
    }

    /**
     * @dataProvider overridePathProvider
     */
    public function test_route_path_can_be_overridden_via_env(string $override): void
    {
        $reflection = new ReflectionClass(GraphqlEndpointPayload::class);
        /** @var AsPayload $route */
        $route = $reflection->getAttributes(AsPayload::class)[0]->newInstance();

        $previous = getenv('SEMITEXA_GRAPHQL_ROUTE_PATH');
        putenv("SEMITEXA_GRAPHQL_ROUTE_PATH={$override}");
        try {
            self::assertSame(
                $override,
                EnvValueResolver::resolve($route->path),
                'EnvValueResolver must return the SEMITEXA_GRAPHQL_ROUTE_PATH value when set.',
            );
        } finally {
            if ($previous === false) {
                putenv('SEMITEXA_GRAPHQL_ROUTE_PATH');
            } else {
                putenv("SEMITEXA_GRAPHQL_ROUTE_PATH={$previous}");
            }
        }
    }

    public function test_endpoint_handler_is_bound_to_endpoint_payload(): void
    {
        $reflection = new ReflectionClass(GraphqlEndpointHandler::class);

        self::assertSame('Semitexa\\Graphql\\Application\\Handler\\PayloadHandler', $reflection->getNamespaceName());

        $attributes = $reflection->getAttributes(AsPayloadHandler::class);
        self::assertCount(1, $attributes, 'GraphqlEndpointHandler must declare exactly one #[AsPayloadHandler].');

        /** @var AsPayloadHandler $binding */
        $binding = $attributes[0]->newInstance();

        self::assertSame(GraphqlEndpointPayload::class, $binding->payload);
        self::assertSame(GraphqlEndpointResource::class, $binding->resource);
    }

    public function test_no_legacy_endpoint_trio_in_playground_module(): void
    {
        // The Playground module hosted the endpoint trio prior to this refactor.
        // If any of these classes reappear, a regression has reintroduced the
        // wrong ownership boundary.
        self::assertFalse(
            class_exists('Semitexa\\Modules\\Playground\\Graphql\\Endpoint\\Payload\\GraphqlEndpointPayload'),
            'Legacy Playground endpoint payload reappeared. The POST /graphql route belongs in semitexa-graphql.',
        );
        self::assertFalse(
            class_exists('Semitexa\\Modules\\Playground\\Graphql\\Endpoint\\Handler\\GraphqlEndpointHandler'),
            'Legacy Playground endpoint handler reappeared. The POST /graphql adapter belongs in semitexa-graphql.',
        );
        self::assertFalse(
            class_exists('Semitexa\\Modules\\Playground\\Graphql\\Endpoint\\Resource\\GraphqlEndpointResource'),
            'Legacy Playground endpoint resource reappeared. The POST /graphql resource belongs in semitexa-graphql.',
        );
    }
}
