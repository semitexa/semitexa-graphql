<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Semitexa\Graphql\Discovery\GraphqlOperationRegistry;
use Semitexa\Graphql\Pipeline\GraphqlErrorMapper;
use Semitexa\Graphql\Application\Service\Runtime\ContainerHandlerInvoker;
use Semitexa\Graphql\Application\Service\Runtime\PayloadArgsHydrator;
use Semitexa\Graphql\Application\Service\Runtime\RenderContextResourceSerializer;
use Semitexa\Graphql\Application\Service\Runtime\WebonyxGraphqlExecutor;
use Semitexa\Graphql\Application\Service\Schema\OutputTypeRegistry;
use Semitexa\Graphql\Application\Service\Schema\PayloadArgumentBuilder;
use Semitexa\Graphql\Application\Service\Schema\ScalarTypeMapper;
use Semitexa\Graphql\Application\Service\Schema\SchemaBuilder;
use Semitexa\Graphql\Tests\Fixture\Runtime\RuntimeHandlerFixture;
use Semitexa\Graphql\Tests\Fixture\Runtime\RuntimeOutputFixture;
use Semitexa\Graphql\Tests\Fixture\Runtime\RuntimePayloadFixture;
use Semitexa\Graphql\Tests\Fixture\Runtime\RuntimeResourceFixture;
use Semitexa\Graphql\Tests\Support\StubRouteInspectionRegistry;

/**
 * End-to-end exercise of the runtime: build the schema from a stub route
 * fixture, execute a real GraphQL query, walk it through the
 * Payload→Handler→Resource pipeline, and assert the GraphQL JSON envelope.
 *
 * Uses an in-process container that returns the test handler so the full
 * `Schema → Executor → Resolver → Handler → Resource → Serializer` path
 * runs without booting the framework.
 */
final class GraphqlExecutorEndToEndTest extends TestCase
{
    public function test_executes_real_query_through_payload_handler_resource_pipeline(): void
    {
        $executor = $this->buildExecutor();

        $result = $executor->execute(
            'query Q { runtimeFixture(id: "abc", limit: 5) { id name count enabled score } }'
        );

        self::assertSame([], $result->errors);
        self::assertSame([
            'runtimeFixture' => [
                'id' => 'abc',
                'name' => 'name-abc',
                'count' => 5,
                'enabled' => true,
                'score' => 0.5,
            ],
        ], $result->data);
    }

    public function test_field_selection_is_respected(): void
    {
        $executor = $this->buildExecutor();

        $result = $executor->execute(
            'query Q { runtimeFixture(id: "abc") { id name } }'
        );

        self::assertSame([], $result->errors);
        self::assertSame(['runtimeFixture' => ['id' => 'abc', 'name' => 'name-abc']], $result->data);
    }

    public function test_query_with_variables_resolves_through_args(): void
    {
        $executor = $this->buildExecutor();

        $result = $executor->execute(
            'query Q($id: ID!) { runtimeFixture(id: $id) { id } }',
            variables: ['id' => 'wired'],
            operationName: 'Q',
        );

        self::assertSame([], $result->errors);
        self::assertSame(['runtimeFixture' => ['id' => 'wired']], $result->data);
    }

    public function test_not_found_exception_becomes_NOT_FOUND_error(): void
    {
        $executor = $this->buildExecutor();

        $result = $executor->execute(
            'query Q { runtimeFixture(id: "missing") { id } }'
        );

        self::assertCount(1, $result->errors);
        self::assertSame('NOT_FOUND', $result->errors[0]['extensions']['code']);
        // GraphQL returns null for the field that errored, but data: { runtimeFixture: null } is kept.
        self::assertSame(['runtimeFixture' => null], $result->data);
    }

    public function test_validation_failure_inside_payload_becomes_VALIDATION_FAILED_error(): void
    {
        $executor = $this->buildExecutor();

        // Empty id passes the schema's NonNull check ("" is a string), but
        // the Payload's setId() throws ValidationException. Confirms the
        // pipeline propagates setter-time validation failures from the
        // GraphQL hydrator up to the executor as VALIDATION_FAILED.
        $result = $executor->execute(
            'query Q { runtimeFixture(id: "") { id } }'
        );

        self::assertCount(1, $result->errors);
        self::assertSame('VALIDATION_FAILED', $result->errors[0]['extensions']['code']);
    }

    public function test_invalid_syntax_returns_GRAPHQL_VALIDATION_error(): void
    {
        $executor = $this->buildExecutor();

        $result = $executor->execute('this is not graphql');

        self::assertNotEmpty($result->errors);
        self::assertSame('GRAPHQL_VALIDATION', $result->errors[0]['extensions']['code']);
        self::assertNull($result->data);
    }

    public function test_unknown_field_returns_GRAPHQL_VALIDATION_error(): void
    {
        $executor = $this->buildExecutor();

        $result = $executor->execute('query { runtimeFixture(id: "x") { nonexistent } }');

        self::assertNotEmpty($result->errors);
        self::assertSame('GRAPHQL_VALIDATION', $result->errors[0]['extensions']['code']);
    }

    public function test_introspection_works_out_of_the_box(): void
    {
        $executor = $this->buildExecutor();

        $result = $executor->execute('{ __schema { queryType { name } } }');

        self::assertSame([], $result->errors);
        self::assertSame(['__schema' => ['queryType' => ['name' => 'Query']]], $result->data);
    }

    // --- Phase 2 (PROMPT 117) Step 2.1 + 2.2 -------------------------------

    public function test_subscription_type_is_present_in_introspection_query_unchanged(): void
    {
        $executor = $this->buildExecutor();

        // Checkpoint 2.1: subscriptionType is non-null AND queryType is still Query.
        $result = $executor->execute(
            '{ __schema { queryType { name } subscriptionType { name } } }'
        );

        self::assertSame([], $result->errors);
        self::assertSame([
            '__schema' => [
                'queryType' => ['name' => 'Query'],
                'subscriptionType' => ['name' => 'Subscription'],
            ],
        ], $result->data);
    }

    public function test_subscription_document_parses_and_validates_no_allowed_values_throw(): void
    {
        $executor = $this->buildExecutor();

        // Pre-Phase-2 this threw "Allowed values: query, mutation" at registry
        // build. It must now parse + validate against the Subscription root.
        $result = $executor->execute(
            'subscription S { runtimeFixtureChanges(id: "abc") { id } }'
        );

        // No GRAPHQL_VALIDATION error (the field exists on the Subscription root).
        $codes = array_map(
            static fn (array $e): mixed => $e['extensions']['code'] ?? null,
            $result->errors,
        );
        self::assertNotContains('GRAPHQL_VALIDATION', $codes);
        // The document parsed + validated and the field resolved to real data.
        self::assertIsArray($result->data);
        self::assertArrayHasKey('runtimeFixtureChanges', $result->data);
    }

    public function test_subscription_executes_one_shot_query_style_returning_real_rows(): void
    {
        $executor = $this->buildExecutor();

        // Step 2.2 checkpoint: a subscription operation run through the normal
        // executor entrypoint resolves its root field exactly as a query field
        // does (webonyx uses executeFields, NOT createSourceEventStream), so it
        // returns a real {data, errors} ExecutionResult with real fixture data.
        $result = $executor->execute(
            'subscription S { runtimeFixtureChanges(id: "abc", limit: 7) { id name count enabled score } }'
        );

        self::assertSame([], $result->errors);
        self::assertSame([
            'runtimeFixtureChanges' => [
                'id' => 'abc',
                'name' => 'name-abc',
                'count' => 7,
                'enabled' => true,
                'score' => 0.5,
            ],
        ], $result->data);
    }

    private function buildExecutor(): WebonyxGraphqlExecutor
    {
        $routes = new StubRouteInspectionRegistry([
            StubRouteInspectionRegistry::metadata(
                payloadClass: RuntimePayloadFixture::class,
                name: 'runtime.fixture',
                path: '/runtime/{id}',
                methods: ['GET'],
                responseClass: RuntimeResourceFixture::class,
                handlers: [['class' => RuntimeHandlerFixture::class]],
            ),
        ]);

        $registry = new GraphqlOperationRegistry();
        (new ReflectionProperty($registry, 'routes'))->setValue($registry, $routes);

        $scalars = new ScalarTypeMapper();
        $outputs = new OutputTypeRegistry();
        $outputs->setScalarsForTest($scalars);
        $argBuilder = new PayloadArgumentBuilder();
        $argBuilder->setScalarsForTest($scalars);

        $handlerInvoker = new ContainerHandlerInvoker();
        $handlerInvoker->setContainerForTest(new InMemoryContainer([
            RuntimeHandlerFixture::class => new RuntimeHandlerFixture(),
        ]));

        $schemaBuilder = new SchemaBuilder();
        $schemaBuilder->setCollaboratorsForTest(
            operations: $registry,
            outputTypes: $outputs,
            argumentBuilder: $argBuilder,
            payloadHydrator: new PayloadArgsHydrator(),
            handlerInvoker: $handlerInvoker,
            resourceSerializer: new RenderContextResourceSerializer(),
        );

        $executor = new WebonyxGraphqlExecutor();
        $executor->setCollaboratorsForTest($schemaBuilder, new GraphqlErrorMapper());
        return $executor;
    }
}
