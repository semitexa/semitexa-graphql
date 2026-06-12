<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Discovery;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Semitexa\Graphql\Discovery\GraphqlOperationRegistry;
use Semitexa\Graphql\Tests\Fixture\CaseInsensitiveRootTypeFixture;
use Semitexa\Graphql\Tests\Fixture\DuplicateFieldFixtureA;
use Semitexa\Graphql\Tests\Fixture\DuplicateFieldFixtureB;
use Semitexa\Graphql\Tests\Fixture\InvalidRootTypeFixture;
use Semitexa\Graphql\Tests\Fixture\MissingOutputClassFixture;
use Semitexa\Graphql\Tests\Fixture\MutationPayloadFixture;
use Semitexa\Graphql\Tests\Fixture\QueryPayloadFixture;
use Semitexa\Graphql\Tests\Fixture\RepeatedExposureFixture;
use Semitexa\Graphql\Tests\Fixture\SubscriptionPayloadFixture;
use Semitexa\Graphql\Tests\Fixture\UnmarkedPayloadFixture;
use Semitexa\Graphql\Tests\Support\StubRouteInspectionRegistry;

final class GraphqlOperationRegistryTest extends TestCase
{
    public function test_empty_route_set_yields_empty_operation_list(): void
    {
        $registry = $this->makeRegistry([]);

        self::assertSame([], $registry->all());
        self::assertSame([], $registry->queries());
        self::assertSame([], $registry->mutations());
        self::assertNull($registry->find('query', 'whatever'));
    }

    public function test_routes_without_attribute_are_skipped(): void
    {
        $registry = $this->makeRegistry([
            StubRouteInspectionRegistry::metadata(UnmarkedPayloadFixture::class, 'unmarked'),
            StubRouteInspectionRegistry::metadata(QueryPayloadFixture::class, 'q'),
        ]);

        $ops = $registry->all();
        self::assertCount(1, $ops);
        self::assertSame('foo', $ops[0]->field);
        self::assertSame(QueryPayloadFixture::class, $ops[0]->payloadClass);
    }

    public function test_routes_whose_payload_class_does_not_exist_are_skipped(): void
    {
        $registry = $this->makeRegistry([
            StubRouteInspectionRegistry::metadata('Definitely\\Not\\A\\Class', 'ghost'),
            StubRouteInspectionRegistry::metadata(QueryPayloadFixture::class, 'q'),
        ]);

        $ops = $registry->all();
        self::assertCount(1, $ops, 'phantom class is skipped, real one survives');
    }

    public function test_queries_and_mutations_partition_by_root_type(): void
    {
        $registry = $this->makeRegistry([
            StubRouteInspectionRegistry::metadata(QueryPayloadFixture::class, 'q'),
            StubRouteInspectionRegistry::metadata(MutationPayloadFixture::class, 'm'),
        ]);

        self::assertCount(1, $registry->queries());
        self::assertSame('foo', $registry->queries()[0]->field);
        self::assertCount(1, $registry->mutations());
        self::assertSame('bar', $registry->mutations()[0]->field);
    }

    public function test_find_locates_operation_by_root_type_and_field(): void
    {
        $registry = $this->makeRegistry([
            StubRouteInspectionRegistry::metadata(QueryPayloadFixture::class, 'q'),
            StubRouteInspectionRegistry::metadata(MutationPayloadFixture::class, 'm'),
        ]);

        self::assertNotNull($registry->find('query', 'foo'));
        self::assertSame('foo', $registry->find('query', 'foo')->field);
        self::assertNotNull($registry->find('mutation', 'bar'));
        self::assertNull($registry->find('query', 'bar'), 'bar is not a query');
        self::assertNull($registry->find('mutation', 'foo'), 'foo is not a mutation');
        self::assertNull($registry->find('query', 'unknown'));
    }

    public function test_find_normalizes_root_type_case(): void
    {
        $registry = $this->makeRegistry([
            StubRouteInspectionRegistry::metadata(QueryPayloadFixture::class, 'q'),
        ]);

        self::assertNotNull($registry->find('QUERY', 'foo'));
        self::assertNotNull($registry->find('  query  ', 'foo'));
    }

    public function test_root_type_is_normalized_when_attribute_uses_uppercase_or_whitespace(): void
    {
        $registry = $this->makeRegistry([
            StubRouteInspectionRegistry::metadata(CaseInsensitiveRootTypeFixture::class, 'norm'),
        ]);

        $ops = $registry->all();
        self::assertCount(1, $ops);
        self::assertSame('query', $ops[0]->rootType);
    }

    public function test_invalid_root_type_throws(): void
    {
        $registry = $this->makeRegistry([
            StubRouteInspectionRegistry::metadata(InvalidRootTypeFixture::class, 'bad'),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported GraphQL root type "telepathy"');
        $registry->all();
    }

    public function test_subscription_root_type_is_accepted_and_partitioned(): void
    {
        $registry = $this->makeRegistry([
            StubRouteInspectionRegistry::metadata(QueryPayloadFixture::class, 'q'),
            StubRouteInspectionRegistry::metadata(SubscriptionPayloadFixture::class, 's'),
        ]);

        // subscription no longer throws; it partitions away from queries/mutations.
        self::assertCount(1, $registry->subscriptions());
        self::assertCount(1, $registry->queries());
        self::assertCount(0, $registry->mutations());

        $sub = $registry->subscriptions()[0];
        self::assertSame('thingChanges', $sub->field);
        self::assertSame('subscription', $sub->rootType);
        self::assertTrue($sub->isSubscription());
        self::assertFalse($sub->isQuery());

        // watchScopes is parsed/stored, with non-string / empty entries filtered.
        self::assertSame(['things'], $sub->watchScopes);

        self::assertNotNull($registry->find('subscription', 'thingChanges'));
    }

    public function test_repeatable_attribute_yields_one_operation_per_exposure(): void
    {
        $registry = $this->makeRegistry([
            StubRouteInspectionRegistry::metadata(RepeatedExposureFixture::class, 'r'),
        ]);

        // One Payload → two operations, same payloadClass, different root types.
        $ops = $registry->all();
        self::assertCount(2, $ops);
        self::assertCount(1, $registry->queries());
        self::assertCount(1, $registry->subscriptions());
        self::assertSame(
            RepeatedExposureFixture::class,
            $registry->subscriptions()[0]->payloadClass,
        );
        self::assertSame(
            $registry->queries()[0]->payloadClass,
            $registry->subscriptions()[0]->payloadClass,
            'both operations are driven by the same Payload/handler',
        );
    }

    public function test_missing_output_class_throws(): void
    {
        $registry = $this->makeRegistry([
            StubRouteInspectionRegistry::metadata(MissingOutputClassFixture::class, 'broken'),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');
        $registry->all();
    }

    public function test_duplicate_field_within_same_root_type_throws(): void
    {
        $registry = $this->makeRegistry([
            StubRouteInspectionRegistry::metadata(DuplicateFieldFixtureA::class, 'a'),
            StubRouteInspectionRegistry::metadata(DuplicateFieldFixtureB::class, 'b'),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate GraphQL operation "query:sameField"');
        $registry->all();
    }

    public function test_results_are_cached_across_calls(): void
    {
        $stub = new StubRouteInspectionRegistry([
            StubRouteInspectionRegistry::metadata(QueryPayloadFixture::class, 'q'),
        ]);
        $registry = $this->makeRegistry($stub);

        $first = $registry->all();
        $second = $registry->all();

        // Identity check: subsequent calls return the same array (cached) rather
        // than re-reflecting and re-allocating.
        self::assertSame($first, $second);
    }

    public function test_extracts_handler_class_names_from_route_metadata(): void
    {
        $route = StubRouteInspectionRegistry::metadata(
            QueryPayloadFixture::class,
            'q',
            handlers: [
                ['class' => 'Foo\\HandlerA'],
                ['class' => 'Foo\\HandlerB'],
                ['class' => 'Foo\\HandlerA'], // duplicate is collapsed
                ['notClass' => 'noise'],      // missing 'class' is dropped
            ],
        );
        $registry = $this->makeRegistry([$route]);

        $op = $registry->all()[0];
        self::assertSame(['Foo\\HandlerA', 'Foo\\HandlerB'], $op->handlerClasses);
    }

    /**
     * @param list<\Semitexa\Core\Discovery\ResolvedRouteMetadata>|StubRouteInspectionRegistry $routes
     */
    private function makeRegistry(array|StubRouteInspectionRegistry $routes): GraphqlOperationRegistry
    {
        $stub = $routes instanceof StubRouteInspectionRegistry
            ? $routes
            : new StubRouteInspectionRegistry($routes);

        $registry = new GraphqlOperationRegistry();
        (new ReflectionProperty($registry, 'routes'))->setValue($registry, $stub);
        return $registry;
    }
}
