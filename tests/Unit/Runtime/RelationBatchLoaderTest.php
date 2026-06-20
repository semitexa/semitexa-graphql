<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Runtime;

use GraphQL\Executor\Promise\Adapter\SyncPromise;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Resource\RelationResolverInterface;
use Semitexa\Core\Resource\RenderContext;
use Semitexa\Graphql\Application\Service\Runtime\LazyRelationResolver;
use Semitexa\Graphql\Application\Service\Runtime\RelationBatchLoader;

/**
 * Locks the DataLoader contract: one `resolveBatch()` per resolver class per
 * drained level, carrying every parent buffered at that level
 * (`tk-gql-nested-batch-list-siblings`).
 */
final class RelationBatchLoaderTest extends TestCase
{
    public function test_buffers_siblings_into_a_single_dispatch(): void
    {
        [$loader, $counter] = $this->loaderWithCountingResolver();

        $captured = [];
        $loader->load('addr.resolver', 'demo.customer', 'c1', true)
            ->then(function ($v) use (&$captured): void {
                $captured['c1'] = $v;
            });
        $loader->load('addr.resolver', 'demo.customer', 'c2', true)
            ->then(function ($v) use (&$captured): void {
                $captured['c2'] = $v;
            });

        // Nothing dispatched until the promise queue drains (webonyx does this
        // once per level).
        self::assertSame(0, $counter->calls);

        SyncPromise::runQueue();

        self::assertSame(1, $counter->calls, 'both siblings collapse into one resolveBatch');
        self::assertSame(2, $counter->maxParents);
        self::assertSame(['city' => 'for-c1'], $captured['c1']);
        self::assertSame(['city' => 'for-c2'], $captured['c2']);
    }

    public function test_a_second_level_redispatches_without_clobbering_earlier_results(): void
    {
        [$loader, $counter] = $this->loaderWithCountingResolver();

        $first = [];
        $loader->load('addr.resolver', 'demo.customer', 'c1', true)
            ->then(function ($v) use (&$first): void {
                $first['c1'] = $v;
            });
        SyncPromise::runQueue();

        $second = [];
        $loader->load('addr.resolver', 'demo.customer', 'c9', true)
            ->then(function ($v) use (&$second): void {
                $second['c9'] = $v;
            });
        SyncPromise::runQueue();

        self::assertSame(2, $counter->calls, 'a fresh level re-dispatches');
        self::assertSame(['city' => 'for-c1'], $first['c1']);
        self::assertSame(['city' => 'for-c9'], $second['c9']);
    }

    public function test_missing_parent_degrades_to_empty_list(): void
    {
        [$loader] = $this->loaderWithCountingResolver(returnNothing: true);

        $captured = ['x' => 'untouched'];
        $loader->load('addr.resolver', 'demo.customer', 'c1', true)
            ->then(function ($v) use (&$captured): void {
                $captured['v'] = $v;
            });
        SyncPromise::runQueue();

        self::assertSame([], $captured['v']);
    }

    /**
     * @return array{0: RelationBatchLoader, 1: object}
     */
    private function loaderWithCountingResolver(bool $returnNothing = false): array
    {
        $counter = new class {
            public int $calls = 0;
            public int $maxParents = 0;
        };
        $resolver = new class ($counter, $returnNothing) implements RelationResolverInterface {
            public function __construct(private object $counter, private bool $returnNothing)
            {
            }

            public function resolveBatch(array $parents, RenderContext $ctx): array
            {
                $this->counter->calls++;
                $this->counter->maxParents = max($this->counter->maxParents, count($parents));
                if ($this->returnNothing) {
                    return [];
                }
                $out = [];
                foreach ($parents as $parent) {
                    $out[$parent->urn()] = ['city' => 'for-' . $parent->id];
                }
                return $out;
            }
        };

        $lazy = new LazyRelationResolver();
        $lazy->setContainerForTest($this->container('addr.resolver', $resolver));

        return [new RelationBatchLoader($lazy), $counter];
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
