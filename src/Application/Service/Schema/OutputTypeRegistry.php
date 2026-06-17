<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Application\Service\Schema;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use ReflectionClass;
use ReflectionNamedType;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Resource\Metadata\ResourceFieldKind;
use Semitexa\Core\Resource\Metadata\ResourceFieldMetadata;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\Metadata\ResourceObjectMetadata;
use Semitexa\Graphql\Application\Service\Runtime\LazyRelationResolver;
use Semitexa\Graphql\Application\Service\Runtime\RelationBatchLoader;

/**
 * Builds and caches webonyx ObjectType instances for Semitexa output classes.
 *
 * For a given output class (an immutable DTO like `Article`) this maps each
 * `public readonly` property to a GraphQL field of the same name. Scalar
 * properties become webonyx scalars; relation fields declared on a
 * `#[ResourceObject]` (RefOne / RefMany / Embedded* / Union) become traversable
 * nested object fields whose type is itself derived from the related
 * `#[ResourceObject]`'s metadata. The cache is keyed by FQCN so a single
 * `Article` ObjectType is shared across every operation that returns it.
 *
 * Default field resolution: if the resolver upstream produces an array (the
 * Resource's `toArray()` representation), each key is read by name. A relation
 * field first reads any value the handler eagerly nested under its key; failing
 * that, when the relation declares a `#[ResolveWith]` resolver, it is loaded
 * lazily for that one parent via {@see LazyRelationResolver} (webonyx only
 * invokes the resolver when the query selects the field). Relation fields are
 * therefore emitted with their declared nullability rather than forced non-null,
 * since an unresolvable relation degrades to null/empty.
 *
 * Recursion / cycles: a type's fields are assembled through a webonyx lazy
 * `fields` thunk and the ObjectType is cached BEFORE the thunk runs, so a cyclic
 * relation (Article → Author → Article) resolves to the already-cached instance
 * instead of recursing forever.
 */
#[AsService]
final class OutputTypeRegistry
{
    #[InjectAsReadonly]
    protected ScalarTypeMapper $scalars;

    /**
     * Canonical Resource metadata — the SAME registry OpenAPI and SchemaBuilder
     * read. Used to resolve a relation field's `target` FQCN to the related
     * Resource's metadata so its nested ObjectType can be built. When absent
     * (a partial test wiring that only set the scalar mapper) relation fields
     * degrade to "skipped", i.e. scalar-only behaviour.
     */
    #[InjectAsReadonly]
    protected ResourceMetadataRegistry $resources;

    /**
     * Loads a relation field's value on demand through its `#[ResolveWith]`
     * resolver when the handler did not eagerly nest it. Guarded by `isset`
     * everywhere it is used, so a partial test wiring (scalar mapper only)
     * keeps relation fields resolving from eagerly-nested data alone.
     */
    #[InjectAsReadonly]
    protected LazyRelationResolver $lazyRelations;

    /** @var array<string, ObjectType> */
    private array $types = [];

    /** @var array<string, UnionType> keyed by the union's member-set signature. */
    private array $unions = [];

    /** @var array<string, array<string, mixed>> memoized eager scalar/id field defs, keyed by FQCN. */
    private array $scalarFieldsMemo = [];

    /** @var array<string, string> GraphQL type name → the class/key that claimed it. */
    private array $namesSeen = [];

    /**
     * GraphQL type names come from the class basename — the SAME rule OpenAPI's
     * component naming uses ({@see \Semitexa\Api\OpenApi\Schema\ResourceSchemaGenerator::componentName}),
     * so one #[ResourceObject] gets one name on both surfaces. The trade-off is
     * that two classes sharing a short name collide; webonyx would fatal the
     * whole schema with a cryptic "unique named types" error, so fail FAST here
     * with an actionable message instead.
     */
    private function registerTypeName(string $name, string $owner): void
    {
        if (isset($this->namesSeen[$name]) && $this->namesSeen[$name] !== $owner) {
            throw new \InvalidArgumentException(sprintf(
                'Duplicate GraphQL type name "%s": both %s and %s resolve to it. GraphQL '
                . 'type names are the class basename (matching OpenAPI components); rename '
                . 'one of the two classes so their short names differ.',
                $name,
                $this->namesSeen[$name],
                $owner,
            ));
        }

        $this->namesSeen[$name] = $owner;
    }

    /**
     * One field definition, shared by every build path: nullable → non-null
     * wrap plus a caller-supplied resolver. Centralizing it keeps DTO-derived
     * ({@see self::get()}), Resource scalar-derived and relation-derived field
     * shapes byte-identical apart from how they resolve their value.
     *
     * @param callable(mixed, array<string, mixed>, mixed, ResolveInfo): mixed $resolve
     * @return array<string, mixed>
     */
    private function fieldDefinition(Type $type, bool $nullable, ?string $description, callable $resolve): array
    {
        return [
            'type' => $nullable ? $type : Type::nonNull($type),
            'description' => $description,
            'resolve' => $resolve,
        ];
    }

    /**
     * The default scalar resolver: read the field by name from the resolved
     * source, which is either the Resource's `toArray()` representation or a
     * DTO object ({@see self::extractField}).
     *
     * @return callable(mixed, array<string, mixed>, mixed, ResolveInfo): mixed
     */
    private static function scalarResolver(string $fieldName): callable
    {
        return static fn (mixed $source, array $args, mixed $context, ResolveInfo $info): mixed
            => self::extractField($source, $fieldName);
    }

    /**
     * The resolver for a relation field: prefer data the handler already nested
     * under the field's key (eager path); otherwise, when the relation declares
     * a `#[ResolveWith]` resolver, load it lazily. webonyx only invokes this
     * when the query selects the field, so unselected relations cost nothing.
     *
     * When the execution supplies a {@see RelationBatchLoader} as the context
     * value, the lazy load is BATCHED across list siblings (one `resolveBatch()`
     * per resolver class per level, returned as a webonyx `Deferred`). Without a
     * loader (e.g. a unit test that wires only the registry) it falls back to a
     * synchronous one-parent load.
     *
     * @return callable(mixed, array<string, mixed>, mixed, ResolveInfo): mixed
     */
    private function relationResolver(ResourceFieldMetadata $field, ResourceObjectMetadata $meta): callable
    {
        $fieldName     = $field->name;
        $isList        = $field->isList();
        $resolverClass = $field->resolverClass;
        $parentType    = $meta->type;
        $parentIdField = $meta->idField;

        return function (mixed $source, array $args, mixed $context, ResolveInfo $info) use (
            $fieldName,
            $isList,
            $resolverClass,
            $parentType,
            $parentIdField
        ): mixed {
            // Eager path: the handler already embedded the relation value.
            $eager = self::extractField($source, $fieldName);
            if ($eager !== null) {
                return $eager;
            }

            // Lazy path: dispatch the declared resolver for this parent.
            if ($resolverClass === null || $parentIdField === null) {
                return $isList ? [] : null;
            }

            $parentId = self::extractField($source, $parentIdField);
            if (!is_string($parentId) && !is_int($parentId)) {
                return $isList ? [] : null;
            }

            // Batched (list-sibling-safe) path when the execution provides a loader.
            if ($context instanceof RelationBatchLoader) {
                return $context->load($resolverClass, $parentType, (string) $parentId, $isList);
            }

            // Synchronous fallback (one resolveBatch per parent).
            if (isset($this->lazyRelations)) {
                return $this->lazyRelations->resolve($resolverClass, $parentType, (string) $parentId, $isList);
            }

            return $isList ? [] : null;
        };
    }

    /**
     * Shared assembly tail: fail-fast on a duplicate type name, then cache and
     * build the ObjectType. The fields are passed as a webonyx lazy thunk and
     * the ObjectType is cached BEFORE the thunk can run, so a cyclic relation
     * resolves to this same cached instance. Both build paths end here so
     * naming + caching stay in one place.
     *
     * @param callable(): array<string, mixed> $fields
     */
    private function assembleObjectType(string $class, string $name, callable $fields): ObjectType
    {
        $this->registerTypeName($name, $class);

        return $this->types[$class] = new ObjectType([
            'name' => $name,
            'fields' => $fields,
        ]);
    }

    public function get(string $outputClass): ObjectType
    {
        if (isset($this->types[$outputClass])) {
            return $this->types[$outputClass];
        }

        $reflection = new ReflectionClass($outputClass);

        $fields = [];
        foreach ($reflection->getProperties() as $property) {
            if (!$property->isPublic() || $property->isStatic()) {
                continue;
            }
            $type = $property->getType();
            if (!$type instanceof ReflectionNamedType) {
                continue;
            }
            $scalar = $this->scalars->mapForOutputField($type->getName(), $property->getName());
            if ($scalar === null) {
                continue;
            }
            $fields[$property->getName()] = $this->fieldDefinition(
                $scalar,
                $type->allowsNull(),
                null,
                self::scalarResolver($property->getName()),
            );
        }

        return $this->assembleObjectType($outputClass, $reflection->getShortName(), static fn (): array => $fields);
    }

    /**
     * Builds (and caches) a webonyx ObjectType from a Resource's canonical
     * metadata — the SAME `#[ResourceObject]` contract OpenAPI derives its
     * response schemas from. This is the single-source-of-truth path: change
     * the Resource, both REST and GraphQL update together. It supersedes the
     * ad-hoc reflection of an `#[ExposeAsGraphql(output: ...)]` DTO.
     *
     * Scalar + id fields are assembled eagerly (cheap, no recursion) so we know
     * up front whether the type has at least one field. Relation fields
     * (RefOne / RefMany / Embedded* / Union) are assembled inside the lazy
     * thunk, where it is safe to recurse into related types.
     */
    public function forResource(ResourceObjectMetadata $meta): ?ObjectType
    {
        if (isset($this->types[$meta->class])) {
            return $this->types[$meta->class];
        }

        $scalarFields = $this->scalarFields($meta);

        // A type with no scalar/id fields AND no traversable relation would be a
        // fieldless ObjectType, which webonyx rejects — fatalling the ENTIRE
        // schema. Degrade THIS resource to null (the caller falls back to the
        // Json scalar) instead of crashing every operation.
        if ($scalarFields === [] && !$this->hasTraversableRelation($meta)) {
            StaticLoggerBridge::warning('graphql', sprintf(
                'Resource %s exposes no scalar/id or traversable relation GraphQL fields; '
                . 'its field falls back to the Json scalar.',
                $meta->class,
            ));

            return null;
        }

        // Name by class basename — identical to OpenAPI's component naming, so a
        // single #[ResourceObject] has one name across both surfaces. The thunk
        // merges the eager scalar fields with the lazily-built relation fields.
        return $this->assembleObjectType(
            $meta->class,
            (new ReflectionClass($meta->class))->getShortName(),
            fn (): array => $scalarFields + $this->relationFields($meta),
        );
    }

    /**
     * Eager scalar + id field defs for a Resource, memoized by FQCN. Memoizing
     * keeps {@see self::hasTraversableRelation()} (which probes a related
     * resource for emittable scalars) and the actual field build in exact
     * agreement, so we never promise a relation field whose nested type turns
     * out empty.
     *
     * @return array<string, mixed>
     */
    private function scalarFields(ResourceObjectMetadata $meta): array
    {
        if (isset($this->scalarFieldsMemo[$meta->class])) {
            return $this->scalarFieldsMemo[$meta->class];
        }

        $reflection = new ReflectionClass($meta->class);

        $fields = [];
        foreach ($meta->fields as $field) {
            $isId = $meta->idField !== null && $field->name === $meta->idField;
            if ($field->kind !== ResourceFieldKind::Scalar && !$isId) {
                continue;
            }
            if (!$reflection->hasProperty($field->name)) {
                continue;
            }
            $type = $reflection->getProperty($field->name)->getType();
            if (!$type instanceof ReflectionNamedType) {
                continue;
            }
            // The metadata already tells us which field is the identifier, so map
            // it to GraphQL `ID` directly — regardless of its name. Relying on
            // ScalarTypeMapper's name-based heuristic would mis-render a
            // #[ResourceId] not called id/uuid/slug (e.g. `ref`) as a plain String.
            $scalar = $isId
                ? Type::id()
                : $this->scalars->mapForOutputField($type->getName(), $field->name);
            if ($scalar === null) {
                continue;
            }
            $fields[$field->name] = $this->fieldDefinition(
                $scalar,
                $field->nullable,
                $field->description !== '' ? $field->description : null,
                self::scalarResolver($field->name),
            );
        }

        return $this->scalarFieldsMemo[$meta->class] = $fields;
    }

    /**
     * Lazily-built relation field defs for a Resource. Runs inside the webonyx
     * `fields` thunk — by which point this Resource's own ObjectType is already
     * cached, so recursing into related (and even cyclic) types is safe. A
     * relation whose target cannot be typed is skipped (logged), never emitted
     * as a broken field.
     *
     * @return array<string, mixed>
     */
    private function relationFields(ResourceObjectMetadata $meta): array
    {
        $fields = [];
        foreach ($meta->fields as $field) {
            $isId = $meta->idField !== null && $field->name === $meta->idField;
            if ($field->kind === ResourceFieldKind::Scalar || $isId) {
                continue;
            }

            $relationType = $this->relationFieldType($field);
            if ($relationType === null) {
                StaticLoggerBridge::warning('graphql', sprintf(
                    'Relation field %s::$%s (kind %s) has no typable target and is omitted '
                    . 'from the GraphQL schema.',
                    $meta->class,
                    $field->name,
                    $field->kind->value,
                ));
                continue;
            }

            $fields[$field->name] = $this->fieldDefinition(
                $relationType,
                $field->nullable,
                $field->description !== '' ? $field->description : null,
                $this->relationResolver($field, $meta),
            );
        }

        return $fields;
    }

    /**
     * The webonyx output type for a single relation field, or null when it
     * cannot be typed (target not a registered Resource, or that Resource
     * exposes no GraphQL fields). To-many relations wrap a non-null element type
     * in `listOf`; nullability of the field itself is applied by the caller.
     */
    private function relationFieldType(ResourceFieldMetadata $field): ?Type
    {
        if ($field->kind === ResourceFieldKind::Union) {
            $inner = $this->unionType($field);
        } else {
            $inner = $this->nestedTypeFor($field->target);
        }

        if ($inner === null) {
            return null;
        }

        return $field->isList() ? Type::listOf(Type::nonNull($inner)) : $inner;
    }

    /**
     * Resolves a related Resource's ObjectType by target FQCN. Returns null when
     * the registry is unwired, the target is unregistered, or the target yields
     * no GraphQL fields.
     */
    private function nestedTypeFor(?string $target): ?ObjectType
    {
        if ($target === null || $target === '' || !isset($this->resources)) {
            return null;
        }

        $meta = $this->resources->get($target);
        if ($meta === null) {
            return null;
        }

        return $this->forResource($meta);
    }

    /**
     * Whether a Resource has at least one relation that WILL produce a field —
     * i.e. a Ref/Embedded whose target is a registered Resource with emittable
     * scalars, or a Union with at least one such member. Used so a relation-only
     * Resource is not promised an ObjectType it cannot fill (which webonyx would
     * reject). Shallow by design: it probes a related Resource's scalar fields
     * (memoized, non-recursive) but never builds the nested ObjectType.
     */
    private function hasTraversableRelation(ResourceObjectMetadata $meta): bool
    {
        foreach ($meta->fields as $field) {
            if ($field->kind === ResourceFieldKind::Scalar) {
                continue;
            }
            if ($field->kind === ResourceFieldKind::Union) {
                foreach ($field->unionTargets ?? [] as $target) {
                    if ($this->targetHasScalars($target)) {
                        return true;
                    }
                }
                continue;
            }
            if ($this->targetHasScalars($field->target)) {
                return true;
            }
        }

        return false;
    }

    private function targetHasScalars(?string $target): bool
    {
        if ($target === null || $target === '' || !isset($this->resources)) {
            return false;
        }

        $meta = $this->resources->get($target);

        return $meta !== null && $this->scalarFields($meta) !== [];
    }

    /**
     * Builds (and caches) a webonyx UnionType over a Union field's member
     * Resources, discriminating concrete types by the field's `discriminator`
     * value. Members that cannot be typed are dropped; null when fewer than one
     * member survives. Cached by the member-set signature so the same union used
     * in two places yields one type.
     */
    private function unionType(ResourceFieldMetadata $field): ?UnionType
    {
        $targets = $field->unionTargets ?? [];
        if ($targets === [] || !isset($this->resources)) {
            return null;
        }

        $signature = implode('|', $targets);
        if (isset($this->unions[$signature])) {
            return $this->unions[$signature];
        }

        /** @var array<string, ObjectType> $byDiscriminator value (resource type + short name) → member type */
        $byDiscriminator = [];
        $members = [];
        $shortNames = [];
        foreach ($targets as $target) {
            $meta = $this->resources->get($target);
            if ($meta === null) {
                continue;
            }
            $memberType = $this->forResource($meta);
            if ($memberType === null) {
                continue;
            }
            $members[] = $memberType;
            $shortName = (new ReflectionClass($meta->class))->getShortName();
            $shortNames[] = $shortName;
            // Discriminator values may carry either the canonical resource type
            // or the class short name; index both so resolveType matches either.
            $byDiscriminator[$meta->type] = $memberType;
            $byDiscriminator[$shortName] = $memberType;
        }

        if ($members === []) {
            return null;
        }

        $name = implode('Or', $shortNames) . 'Union';
        $this->registerTypeName($name, 'union:' . $signature);

        $discriminator = $field->discriminator;
        $fallback = $members[0];

        return $this->unions[$signature] = new UnionType([
            'name' => $name,
            'types' => $members,
            'resolveType' => static function (mixed $value) use ($byDiscriminator, $discriminator, $fallback): ObjectType {
                $key = null;
                if ($discriminator !== null && $discriminator !== '') {
                    if (is_array($value)) {
                        $key = $value[$discriminator] ?? null;
                    } elseif (is_object($value) && isset($value->{$discriminator})) {
                        $key = $value->{$discriminator};
                    }
                }

                return is_string($key) && isset($byDiscriminator[$key])
                    ? $byDiscriminator[$key]
                    : $fallback;
            },
        ]);
    }

    /**
     * Test seam: allow tests to inject the scalar mapper without a container.
     * @internal
     */
    public function setScalarsForTest(ScalarTypeMapper $scalars): void
    {
        $this->scalars = $scalars;
    }

    /**
     * Test seam: wire the Resource metadata registry so nested relation fields
     * can be built without a container. Without it, relation fields degrade to
     * "skipped" (scalar-only behaviour), preserving pre-existing scalar tests.
     * @internal
     */
    public function setResourcesForTest(ResourceMetadataRegistry $resources): void
    {
        $this->resources = $resources;
    }

    /**
     * Test seam: wire the lazy relation resolver so a selected relation field
     * with a `#[ResolveWith]` resolver loads on demand without a container.
     * Without it, relation fields resolve from eagerly-nested data only.
     * @internal
     */
    public function setLazyRelationsForTest(LazyRelationResolver $lazyRelations): void
    {
        $this->lazyRelations = $lazyRelations;
    }

    private static function extractField(mixed $source, string $fieldName): mixed
    {
        if (is_array($source)) {
            return $source[$fieldName] ?? null;
        }
        if (is_object($source)) {
            if (isset($source->{$fieldName})) {
                return $source->{$fieldName};
            }
            $getter = 'get' . ucfirst($fieldName);
            if (method_exists($source, $getter)) {
                return $source->{$getter}();
            }
            $is = 'is' . ucfirst($fieldName);
            if (method_exists($source, $is)) {
                return $source->{$is}();
            }
        }
        return null;
    }
}
