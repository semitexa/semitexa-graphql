<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Application\Service\Runtime;

use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Graphql\Domain\Contract\GraphqlExecutorInterface;
use Semitexa\Graphql\Domain\Contract\SchemaProviderInterface;
use Semitexa\Graphql\Pipeline\GraphqlErrorMapper;
use Semitexa\Graphql\Domain\Model\GraphqlExecutionResult;

/**
 * webonyx-backed implementation of `GraphqlExecutorInterface`.
 *
 * Responsibilities:
 *   - obtain the cached webonyx Schema from `SchemaProviderInterface`,
 *   - call `GraphQL::executeQuery()` with the supplied document + variables,
 *   - convert the resulting `ExecutionResult` into a Semitexa-shaped
 *     `GraphqlExecutionResult` whose error envelope is shaped by
 *     `GraphqlErrorMapper`.
 *
 * The executor is the only place that talks to webonyx at runtime. Callers
 * (the HTTP endpoint, in-process tests, future transports) only see
 * `GraphqlExecutionResult` and never need to import webonyx classes.
 */
#[SatisfiesServiceContract(of: GraphqlExecutorInterface::class)]
final class WebonyxGraphqlExecutor implements GraphqlExecutorInterface
{
    #[InjectAsReadonly]
    protected SchemaProviderInterface $schema;

    #[InjectAsReadonly]
    protected GraphqlErrorMapper $errorMapper;

    #[InjectAsReadonly]
    protected LazyRelationResolver $lazyRelations;

    public function execute(
        string $query,
        ?array $variables = null,
        ?string $operationName = null,
    ): GraphqlExecutionResult {
        // A fresh per-execution batch loader is handed to webonyx as the context
        // value so relation fields collapse list-sibling N+1 into one
        // resolveBatch() per resolver class per level. Its buffer never leaks
        // across requests on a long-lived Swoole worker.
        $result = GraphQL::executeQuery(
            schema: $this->schema->getSchema(),
            source: $query,
            rootValue: null,
            contextValue: new RelationBatchLoader($this->lazyRelations),
            variableValues: $variables,
            operationName: $operationName,
        );

        // Strip stack traces; the error mapper produces our own envelope.
        $result->setErrorFormatter(fn ($error): array => $this->errorMapper->mapError($error));

        $array = $result->toArray(DebugFlag::NONE);

        /** @var array<string, mixed>|null $data */
        $data = array_key_exists('data', $array) ? $array['data'] : null;
        /** @var list<array<string, mixed>> $errors */
        $errors = $array['errors'] ?? [];
        /** @var array<string, mixed> $extensions */
        $extensions = $array['extensions'] ?? [];

        return new GraphqlExecutionResult(
            data: $data,
            errors: $errors,
            extensions: $extensions,
        );
    }

    /**
     * @internal Test seam: wire collaborators directly without a container.
     */
    public function setCollaboratorsForTest(
        SchemaProviderInterface $schema,
        GraphqlErrorMapper $errorMapper,
        ?LazyRelationResolver $lazyRelations = null,
    ): void {
        $this->schema = $schema;
        $this->errorMapper = $errorMapper;
        // Default to an empty-container loader: relation fields without a wired
        // resolver simply resolve to null/[] (preserves pre-existing test wiring).
        $this->lazyRelations = $lazyRelations ?? new LazyRelationResolver();
    }
}
