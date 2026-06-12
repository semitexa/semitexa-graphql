<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Semitexa\Graphql\Domain\Contract\GraphqlExecutorInterface;
use Semitexa\Graphql\Application\Handler\PayloadHandler\GraphqlEndpointHandler;
use Semitexa\Graphql\Application\Payload\Request\GraphqlEndpointPayload;
use Semitexa\Graphql\Application\Resource\Response\GraphqlEndpointResource;
use Semitexa\Graphql\Application\Service\Runtime\GraphqlOperationTypeResolver;
use Semitexa\Graphql\Application\Service\Runtime\GraphqlSseSubscriptionStreamer;
use Semitexa\Graphql\Domain\Model\GraphqlExecutionResult;

/**
 * Tests the POST /graphql endpoint adapter.
 *
 * Stays at the handler level so the test does not need a full HTTP
 * fixture — the handler IS the GraphQL adapter and its only job is to
 * unpack the Payload, call the executor, and shape the response.
 */
final class GraphqlEndpointHandlerTest extends TestCase
{
    public function test_handler_calls_executor_with_query_variables_and_operation_name(): void
    {
        $executor = $this->stubExecutor(new GraphqlExecutionResult(
            data: ['articles' => [['id' => 'gql_00001']]],
            errors: [],
        ));
        $handler = $this->bindExecutor($executor);

        $payload = new GraphqlEndpointPayload();
        $payload->setQuery('query Q { articles { id } }');
        $payload->setVariables(['k' => 'v']);
        $payload->setOperationName('Q');

        $resource = $handler->handle($payload, new GraphqlEndpointResource());
        $rendered = $resource->getRenderContext();

        self::assertSame('query Q { articles { id } }', $executor->lastQuery);
        self::assertSame(['k' => 'v'], $executor->lastVariables);
        self::assertSame('Q', $executor->lastOperationName);

        self::assertSame(['articles' => [['id' => 'gql_00001']]], $rendered['data']);
        self::assertArrayNotHasKey('errors', $rendered);
    }

    public function test_handler_writes_errors_array_when_executor_reports_errors(): void
    {
        $executor = $this->stubExecutor(new GraphqlExecutionResult(
            data: null,
            errors: [['message' => 'boom', 'extensions' => ['code' => 'INTERNAL_SERVER_ERROR']]],
        ));
        $handler = $this->bindExecutor($executor);

        $payload = new GraphqlEndpointPayload();
        $payload->setQuery('query { bad }');

        $rendered = $handler->handle($payload, new GraphqlEndpointResource())->getRenderContext();

        self::assertArrayHasKey('errors', $rendered);
        self::assertSame('INTERNAL_SERVER_ERROR', $rendered['errors'][0]['extensions']['code']);
    }

    public function test_payload_validation_rejects_empty_query(): void
    {
        $payload = new GraphqlEndpointPayload();

        try {
            $payload->setQuery('');
            self::fail('expected ValidationException');
        } catch (\Semitexa\Core\Exception\ValidationException $e) {
            self::assertArrayHasKey('query', $e->getErrorContext()['errors']);
        }
    }

    public function test_payload_accepts_string_variables_blob(): void
    {
        $payload = new GraphqlEndpointPayload();
        $payload->setVariables('{"id": "gql_00001"}');

        self::assertSame(['id' => 'gql_00001'], $payload->getVariables());
    }

    public function test_payload_normalizes_empty_operation_name_to_null(): void
    {
        $payload = new GraphqlEndpointPayload();
        $payload->setOperationName('   ');

        self::assertNull($payload->getOperationName());
    }

    private function stubExecutor(GraphqlExecutionResult $returns): object
    {
        return new class($returns) implements GraphqlExecutorInterface {
            public string $lastQuery = '';
            /** @var array<string, mixed>|null */
            public ?array $lastVariables = null;
            public ?string $lastOperationName = null;

            public function __construct(private GraphqlExecutionResult $returns) {}

            public function execute(string $query, ?array $variables = null, ?string $operationName = null): GraphqlExecutionResult
            {
                $this->lastQuery = $query;
                $this->lastVariables = $variables;
                $this->lastOperationName = $operationName;
                return $this->returns;
            }
        };
    }

    private function bindExecutor(object $executor): GraphqlEndpointHandler
    {
        $handler = new GraphqlEndpointHandler();
        (new ReflectionProperty($handler, 'executor'))->setValue($handler, $executor);

        // The dual-mode fork seam. With no live Swoole socket (the unit-test
        // case: no coroutine context) tryServe() returns null, so every test
        // here exercises the unchanged one-shot branch — proving the fork does
        // not disturb request-response when SSE is not in play.
        $streamer = new GraphqlSseSubscriptionStreamer();
        (new ReflectionProperty($streamer, 'operationType'))
            ->setValue($streamer, new GraphqlOperationTypeResolver());
        // The streamer also needs the executor (Phase 2: it executes the
        // subscription one-shot for the initial frame). Bind the same stub;
        // these tests never reach execution because tryServe() returns null
        // with no live Swoole socket, but the wiring must be complete.
        (new ReflectionProperty($streamer, 'executor'))->setValue($streamer, $executor);
        (new ReflectionProperty($handler, 'subscriptionStreamer'))->setValue($handler, $streamer);

        return $handler;
    }
}
