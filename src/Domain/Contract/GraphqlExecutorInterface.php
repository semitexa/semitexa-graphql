<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Domain\Contract;

use Semitexa\Graphql\Domain\Model\GraphqlExecutionResult;

/**
 * Executes a GraphQL operation against the discovered Semitexa schema.
 *
 * The executor is the public surface of the runtime layer. It owns parsing,
 * validation, schema resolution, and dispatch through the Payload → Handler
 * → Resource pipeline. Callers (HTTP endpoints, in-process tests) only see
 * GraphQL-shaped input/output — they never touch webonyx types directly.
 */
interface GraphqlExecutorInterface
{
    /**
     * Execute a GraphQL document.
     *
     * @param string                    $query         Raw GraphQL query / mutation source.
     * @param array<string, mixed>|null $variables     Variable bindings keyed by variable name (no leading `$`).
     * @param string|null               $operationName When the document declares multiple operations, names the one to run.
     */
    public function execute(
        string $query,
        ?array $variables = null,
        ?string $operationName = null,
    ): GraphqlExecutionResult;
}
