<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Resource\RelationResolverInterface;
use Semitexa\Core\Resource\RenderContext;
use Semitexa\Core\Resource\ResourceIdentity;
use Semitexa\Graphql\Application\Service\Runtime\LazyRelationResolver;

/**
 * Unit-covers the lazy single-parent relation loader's contract and its
 * defensive degradations (`tk-gql-nested-resolver-expansion-wire`).
 */
final class LazyRelationResolverTest extends TestCase
{
    public function test_dispatches_resolver_for_a_single_parent_bucket(): void
    {
        $resolver = new class implements RelationResolverInterface {
            public function resolveBatch(array $parents, RenderContext $ctx): array
            {
                return [$parents[0]->urn() => ['city' => 'Lviv']];
            }
        };

        $lazy = new LazyRelationResolver();
        $lazy->setContainerForTest($this->container('addr.resolver', $resolver));

        $value = $lazy->resolve('addr.resolver', 'demo.customer', 'c1', isList: false);
        self::assertSame(['city' => 'Lviv'], $value);
    }

    public function test_passes_a_graphql_render_context_and_correct_identity(): void
    {
        $resolver = new class implements RelationResolverInterface {
            /** @var array<string, string> */
            public array $seen = [];

            public function resolveBatch(array $parents, RenderContext $ctx): array
            {
                $this->seen = ['urn' => $parents[0]->urn(), 'profile' => $ctx->profile->value];
                return [];
            }
        };

        $lazy = new LazyRelationResolver();
        $lazy->setContainerForTest($this->container('addr.resolver', $resolver));
        $lazy->resolve('addr.resolver', 'demo.customer', 'c9', isList: true);

        self::assertSame(ResourceIdentity::of('demo.customer', 'c9')->urn(), $resolver->seen['urn']);
        self::assertSame('graphql', $resolver->seen['profile']);
    }

    public function test_to_many_with_no_match_degrades_to_empty_list(): void
    {
        $resolver = new class implements RelationResolverInterface {
            public function resolveBatch(array $parents, RenderContext $ctx): array
            {
                return []; // no key for this parent
            }
        };

        $lazy = new LazyRelationResolver();
        $lazy->setContainerForTest($this->container('addr.resolver', $resolver));

        self::assertSame([], $lazy->resolve('addr.resolver', 'demo.customer', 'c1', isList: true));
        self::assertNull($lazy->resolve('addr.resolver', 'demo.customer', 'c1', isList: false));
    }

    public function test_unregistered_resolver_degrades_to_empty(): void
    {
        $lazy = new LazyRelationResolver();
        $lazy->setContainerForTest($this->container('something.else', new \stdClass()));

        self::assertSame([], $lazy->resolve('addr.resolver', 'demo.customer', 'c1', isList: true));
        self::assertNull($lazy->resolve('addr.resolver', 'demo.customer', 'c1', isList: false));
    }

    public function test_non_resolver_service_degrades_to_empty(): void
    {
        $lazy = new LazyRelationResolver();
        $lazy->setContainerForTest($this->container('addr.resolver', new \stdClass()));

        self::assertNull($lazy->resolve('addr.resolver', 'demo.customer', 'c1', isList: false));
    }

    public function test_resolver_exception_degrades_to_empty(): void
    {
        $resolver = new class implements RelationResolverInterface {
            public function resolveBatch(array $parents, RenderContext $ctx): array
            {
                throw new \RuntimeException('boom');
            }
        };

        $lazy = new LazyRelationResolver();
        $lazy->setContainerForTest($this->container('addr.resolver', $resolver));

        self::assertSame([], $lazy->resolve('addr.resolver', 'demo.customer', 'c1', isList: true));
    }

    private function container(string $id, object $service): ContainerInterface
    {
        return new class ($id, $service) implements ContainerInterface {
            public function __construct(private string $id, private object $service)
            {
            }

            public function has(string $id): bool
            {
                return $id === $this->id;
            }

            public function get(string $id): mixed
            {
                return $this->service;
            }
        };
    }
}
