<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Application\Service\Schema;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\Metadata\ResourceObjectMetadata;
use Semitexa\Graphql\Domain\Contract\GraphqlOperationRegistryInterface;
use Semitexa\Graphql\Domain\Contract\HandlerInvokerInterface;
use Semitexa\Graphql\Domain\Contract\PayloadHydratorInterface;
use Semitexa\Graphql\Domain\Contract\ResourceSerializerInterface;
use Semitexa\Graphql\Domain\Contract\SchemaProviderInterface;
use Semitexa\Graphql\Discovery\ResolvedGraphqlOperation;

/**
 * Builds a webonyx GraphQL Schema from the application's discovered
 * #[ExposeAsGraphql] operations.
 *
 * Each resolved operation contributes one root field whose:
 *   - argument map is derived from its Payload setters,
 *   - output type is derived from its `output:` class (or the `Json` scalar),
 *   - resolver dispatches the bound Handler through Semitexa's existing
 *     Payload → Handler → Resource pipeline.
 *
 * The schema is built lazily and memoized, so repeated executions on the
 * same worker reuse the same Schema instance.
 */
#[SatisfiesServiceContract(of: SchemaProviderInterface::class)]
final class SchemaBuilder implements SchemaProviderInterface
{
    #[InjectAsReadonly]
    protected GraphqlOperationRegistryInterface $operations;

    #[InjectAsReadonly]
    protected OutputTypeRegistry $outputTypes;

    #[InjectAsReadonly]
    protected PayloadArgumentBuilder $argumentBuilder;

    #[InjectAsReadonly]
    protected PayloadHydratorInterface $payloadHydrator;

    #[InjectAsReadonly]
    protected HandlerInvokerInterface $handlerInvoker;

    #[InjectAsReadonly]
    protected ResourceSerializerInterface $resourceSerializer;

    /**
     * Canonical Resource metadata — shared with OpenAPI. When an operation's
     * route response is a registered `#[ResourceObject]`, its GraphQL output
     * type is derived from here instead of from an ad-hoc `output:` DTO.
     */
    #[InjectAsReadonly]
    protected ResourceMetadataRegistry $resourceMetadata;

    private ?Schema $schema = null;

    private ?JsonScalarType $jsonScalar = null;

    public function getSchema(): Schema
    {
        return $this->schema ??= $this->build();
    }

    private function jsonScalar(): JsonScalarType
    {
        return $this->jsonScalar ??= new JsonScalarType();
    }

    /**
     * @internal Test seam: wire all collaborators in a single call without a container.
     */
    public function setCollaboratorsForTest(
        GraphqlOperationRegistryInterface $operations,
        OutputTypeRegistry $outputTypes,
        PayloadArgumentBuilder $argumentBuilder,
        PayloadHydratorInterface $payloadHydrator,
        HandlerInvokerInterface $handlerInvoker,
        ResourceSerializerInterface $resourceSerializer,
        ?ResourceMetadataRegistry $resourceMetadata = null,
    ): void {
        $this->operations = $operations;
        $this->outputTypes = $outputTypes;
        $this->argumentBuilder = $argumentBuilder;
        $this->payloadHydrator = $payloadHydrator;
        $this->handlerInvoker = $handlerInvoker;
        $this->resourceSerializer = $resourceSerializer;
        // Empty registry → no class resolves → legacy output-class path. Keeps
        // pre-existing six-arg test calls behaving exactly as before.
        $this->resourceMetadata = $resourceMetadata ?? new ResourceMetadataRegistry();
        $this->schema = null;
    }

    private function build(): Schema
    {
        $queries = $this->buildRootFields($this->operations->queries());
        $mutations = $this->buildRootFields($this->operations->mutations());

        // GraphQL spec: every Schema must have a Query root with at least one
        // field. Provide a minimal sentinel field if no query operation has been
        // discovered, so an introspection-only client still gets a valid schema.
        if ($queries === []) {
            $queries['_empty'] = [
                'type' => Type::string(),
                'description' => 'Sentinel field — populated when no #[ExposeAsGraphql(rootType: query)] operations have been discovered.',
                'resolve' => static fn (): string => 'No query operations discovered.',
            ];
        }

        $queryType = new ObjectType([
            'name' => 'Query',
            'fields' => $queries,
        ]);

        $config = ['query' => $queryType];

        if ($mutations !== []) {
            $config['mutation'] = new ObjectType([
                'name' => 'Mutation',
                'fields' => $mutations,
            ]);
        }

        // Subscription root: assembled from discovered subscription operations so
        // a `subscription { … }` document parses and validates against the schema
        // (introspection then reports a non-null subscriptionType). Each field
        // resolves through the SAME Payload → Handler → Resource pipeline a query
        // uses — webonyx executes a subscription operation one-shot (query-style)
        // through GraphQL::executeQuery(); the held-open SSE re-run source is
        // wired in Phase 4, not here.
        $subscriptions = $this->buildRootFields($this->operations->subscriptions());
        if ($subscriptions !== []) {
            $config['subscription'] = new ObjectType([
                'name' => 'Subscription',
                'fields' => $subscriptions,
            ]);
        }

        return new Schema($config);
    }

    /**
     * @param list<ResolvedGraphqlOperation> $operations
     * @return array<string, array<string, mixed>>
     */
    private function buildRootFields(array $operations): array
    {
        $fields = [];
        foreach ($operations as $op) {
            if (isset($fields[$op->field])) {
                throw new \InvalidArgumentException(
                    "Duplicate GraphQL field '{$op->field}' detected. "
                    . "Declared by both {$op->payloadClass} and the existing field definition."
                );
            }
            $fields[$op->field] = $this->buildFieldDefinition($op);
        }
        return $fields;
    }

    /** @return array<string, mixed> */
    private function buildFieldDefinition(ResolvedGraphqlOperation $op): array
    {
        $resourceMeta = $this->resolveResourceMeta($op);

        // Canonical path: type derived from the route's #[ResourceObject], the
        // same contract OpenAPI uses. forResource() returns null when the
        // resource yields no GraphQL fields (relation-only) — fall through.
        $outputType = $resourceMeta !== null
            ? $this->outputTypes->forResource($resourceMeta)
            : null;

        if ($outputType === null && $op->outputClass !== null) {
            // Legacy fallback: reflect the declared output DTO.
            $outputType = $this->outputTypes->get($op->outputClass);
        }

        // Last resort: an untyped Json scalar (e.g. deleteArticle, or a resource
        // that couldn't be typed yet).
        $outputType ??= $this->jsonScalar();

        $type = $op->list ? Type::listOf($outputType) : $outputType;

        $args = $this->argumentBuilder->buildFor($op->payloadClass);

        return [
            'type' => $type,
            'description' => $op->description !== '' ? $op->description : null,
            'args' => $args,
            'resolve' => function (mixed $root, array $args, mixed $context, ResolveInfo $info) use ($op): mixed {
                return $this->resolveOperation($op, $args);
            },
        ];
    }

    /**
     * Resolves the canonical Resource metadata backing an operation, if any.
     *
     * Preference order: the route's actual response class, then the legacy
     * `output:` contract. Phase 1 only recognises a class that is itself a
     * registered `#[ResourceObject]`; response-envelope indirection
     * (`#[ProducesResourceObject]`) lives in semitexa-api and is intentionally
     * NOT reached into from here to avoid a cross-package dependency — that
     * bridge belongs in a later, core-level seam.
     */
    private function resolveResourceMeta(ResolvedGraphqlOperation $op): ?ResourceObjectMetadata
    {
        // Defensive: if the registry was not injected (e.g. a partial test
        // wiring), degrade to the legacy output-class path rather than fatal.
        if (!isset($this->resourceMetadata)) {
            return null;
        }

        // Primary path: the route contract already resolved this operation's
        // Resource (via #[ProducesResourceObject]/#[ProducesResourceCollection]
        // or a directly-registered response) to a registry TYPE handle. This is
        // what lets #[ExposeAsGraphql] carry no `output:` at all.
        if ($op->resourceType !== null) {
            $meta = $this->resourceMetadata->findByType($op->resourceType);
            if ($meta !== null) {
                return $meta;
            }
        }

        // Fallback: a class that is itself a registered #[ResourceObject],
        // named directly by the route response or a legacy `output:` contract.
        foreach ([$op->responseClass, $op->outputClass] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && $this->resourceMetadata->has($candidate)) {
                return $this->resourceMetadata->get($candidate);
            }
        }

        return null;
    }

    /**
     * Drives one operation through the existing Semitexa pipeline:
     * args → Payload → Handler → Resource → GraphQL value.
     *
     * Domain exceptions thrown by handlers (`ValidationException`,
     * `NotFoundException`, etc.) bubble up so the executor's error mapper
     * can translate them into GraphQL `errors` with a stable `extensions.code`.
     *
     * @param array<string, mixed> $args
     */
    private function resolveOperation(ResolvedGraphqlOperation $op, array $args): mixed
    {
        $payload = $this->payloadHydrator->hydrate($op->payloadClass, $args);
        $resource = $this->handlerInvoker->invoke($op, $payload);
        return $this->resourceSerializer->serialize($resource);
    }
}
