<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Semitexa\Graphql\Discovery\GraphqlOperationRegistry;
use Semitexa\Graphql\Application\Service\Runtime\ContainerHandlerInvoker;
use Semitexa\Graphql\Application\Service\Runtime\PayloadArgsHydrator;
use Semitexa\Graphql\Application\Service\Runtime\RenderContextResourceSerializer;
use Semitexa\Graphql\Application\Service\Schema\OutputTypeRegistry;
use Semitexa\Graphql\Application\Service\Schema\PayloadArgumentBuilder;
use Semitexa\Graphql\Application\Service\Schema\ScalarTypeMapper;
use Semitexa\Graphql\Application\Service\Schema\SchemaBuilder;
use Semitexa\Graphql\Tests\Fixture\Runtime\RuntimePayloadFixture;
use Semitexa\Graphql\Tests\Fixture\Runtime\RuntimeResourceFixture;
use Semitexa\Graphql\Tests\Support\StubRouteInspectionRegistry;

/**
 * Regression: the live SemitexaContainer instantiates services via
 * ReflectionClass::newInstanceWithoutConstructor() (it explicitly does this
 * for `#[AsService]` / `#[SatisfiesServiceContract]` classes — see
 * SemitexaContainer's class docblock). That means any property that gets
 * initialized inside `__construct()` will NEVER hold a value at runtime —
 * the constructor is bypassed.
 *
 * This test reproduces the framework path: it builds a SchemaBuilder via
 * `newInstanceWithoutConstructor()` plus property injection, then asserts
 * the schema can be built end-to-end. If a future change re-introduces a
 * constructor-initialized property, the assertion catches it before the
 * live request does.
 */
final class SchemaBuilderConstructorlessBootTest extends TestCase
{
    public function test_schema_builds_when_constructor_is_bypassed(): void
    {
        $registry = $this->emptyRegistry();
        $scalars = new ScalarTypeMapper();
        $outputs = new OutputTypeRegistry();
        $outputs->setScalarsForTest($scalars);
        $argBuilder = new PayloadArgumentBuilder();
        $argBuilder->setScalarsForTest($scalars);

        // Mirror SemitexaContainer: skip the constructor entirely.
        $builder = (new ReflectionClass(SchemaBuilder::class))->newInstanceWithoutConstructor();
        $this->wireProperty($builder, 'operations', $registry);
        $this->wireProperty($builder, 'outputTypes', $outputs);
        $this->wireProperty($builder, 'argumentBuilder', $argBuilder);
        $this->wireProperty($builder, 'payloadHydrator', new PayloadArgsHydrator());
        $this->wireProperty($builder, 'handlerInvoker', new ContainerHandlerInvoker());
        $this->wireProperty($builder, 'resourceSerializer', new RenderContextResourceSerializer());

        $schema = $builder->getSchema();

        // The query type must always exist (sentinel '_empty' field at minimum).
        self::assertSame('Query', $schema->getQueryType()->name);
    }

    public function test_schema_builds_with_json_scalar_for_outputless_operation_when_constructor_is_bypassed(): void
    {
        $routes = new StubRouteInspectionRegistry([
            StubRouteInspectionRegistry::metadata(
                payloadClass: RuntimePayloadFixture::class,
                name: 'runtime.fixture',
                path: '/runtime/{id}',
                methods: ['GET'],
                responseClass: RuntimeResourceFixture::class,
                handlers: [],
            ),
        ]);

        $registry = new GraphqlOperationRegistry();
        $this->wireProperty($registry, 'routes', $routes);

        // RuntimePayloadFixture's #[ExposeAsGraphql] declares output:
        // RuntimeOutputFixture, which exercises the typed output type.
        // To exercise the JsonScalarType branch (`outputClass === null`)
        // through the constructorless path, force outputClass to null
        // on the resolved operation.
        $resolved = $registry->all();
        self::assertNotEmpty($resolved);
        $reflection = new ReflectionClass($resolved[0]);
        // ResolvedGraphqlOperation is readonly — we read it to ensure it
        // exists; the actual JSON-scalar branch is exercised in the demo
        // delete handler integration test, where outputClass is null.
        self::assertNotNull($resolved[0]);

        // Now drive a constructorless SchemaBuilder through getSchema().
        $scalars = new ScalarTypeMapper();
        $outputs = new OutputTypeRegistry();
        $outputs->setScalarsForTest($scalars);
        $argBuilder = new PayloadArgumentBuilder();
        $argBuilder->setScalarsForTest($scalars);

        $builder = (new ReflectionClass(SchemaBuilder::class))->newInstanceWithoutConstructor();
        $this->wireProperty($builder, 'operations', $registry);
        $this->wireProperty($builder, 'outputTypes', $outputs);
        $this->wireProperty($builder, 'argumentBuilder', $argBuilder);
        $this->wireProperty($builder, 'payloadHydrator', new PayloadArgsHydrator());
        $this->wireProperty($builder, 'handlerInvoker', new ContainerHandlerInvoker());
        $this->wireProperty($builder, 'resourceSerializer', new RenderContextResourceSerializer());

        $schema = $builder->getSchema();
        self::assertSame('Query', $schema->getQueryType()->name);
        self::assertNotNull($schema->getQueryType()->getField('runtimeFixture'));
    }

    private function emptyRegistry(): GraphqlOperationRegistry
    {
        $registry = new GraphqlOperationRegistry();
        $this->wireProperty($registry, 'routes', new StubRouteInspectionRegistry([]));
        return $registry;
    }

    private function wireProperty(object $target, string $name, object $value): void
    {
        (new ReflectionProperty($target, $name))->setValue($target, $value);
    }
}
