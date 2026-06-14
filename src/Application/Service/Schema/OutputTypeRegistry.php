<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Application\Service\Schema;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use ReflectionClass;
use ReflectionNamedType;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Resource\Metadata\ResourceFieldKind;
use Semitexa\Core\Resource\Metadata\ResourceObjectMetadata;

/**
 * Builds and caches webonyx ObjectType instances for Semitexa output classes.
 *
 * For a given output class (an immutable, scalar-only DTO like `Article`)
 * this maps each `public readonly` property to a GraphQL field of the same
 * name with a webonyx scalar type. The cache is keyed by FQCN so a single
 * `Article` ObjectType is shared across every operation that returns it.
 *
 * Default field resolution: if the resolver upstream produces an array (the
 * Resource's `toArray()` representation), each scalar key is read by name.
 * If it produces an object (for example when a Handler is rewritten to
 * return rich domain DTOs directly), public properties are read.
 *
 * Nested object fields are out of scope here and are tracked under
 * `ep-graphql-nested-resources`.
 */
#[AsService]
final class OutputTypeRegistry
{
    #[InjectAsReadonly]
    protected ScalarTypeMapper $scalars;

    /** @var array<string, ObjectType> */
    private array $types = [];

    /** @var array<string, string> GraphQL type name → the class that claimed it. */
    private array $namesSeen = [];

    /**
     * GraphQL type names come from the class basename — the SAME rule OpenAPI's
     * component naming uses ({@see \Semitexa\Api\OpenApi\Schema\ResourceSchemaGenerator::componentName}),
     * so one #[ResourceObject] gets one name on both surfaces. The trade-off is
     * that two classes sharing a short name collide; webonyx would fatal the
     * whole schema with a cryptic "unique named types" error, so fail FAST here
     * with an actionable message instead.
     */
    private function registerTypeName(string $name, string $class): void
    {
        if (isset($this->namesSeen[$name]) && $this->namesSeen[$name] !== $class) {
            throw new \InvalidArgumentException(sprintf(
                'Duplicate GraphQL type name "%s": both %s and %s resolve to it. GraphQL '
                . 'type names are the class basename (matching OpenAPI components); rename '
                . 'one of the two classes so their short names differ.',
                $name,
                $this->namesSeen[$name],
                $class,
            ));
        }

        $this->namesSeen[$name] = $class;
    }

    /**
     * One field definition, shared by both build paths: nullable → non-null
     * wrap plus the array-or-object `extractField` resolver. Centralizing it
     * keeps DTO-derived ({@see self::get()}) and Resource-derived
     * ({@see self::forResource()}) field shapes byte-identical.
     *
     * @return array<string, mixed>
     */
    private function scalarField(Type $scalar, bool $nullable, string $fieldName, ?string $description): array
    {
        return [
            'type' => $nullable ? $scalar : Type::nonNull($scalar),
            'description' => $description,
            'resolve' => static fn (mixed $source, array $args, mixed $context, ResolveInfo $info): mixed
                => self::extractField($source, $fieldName),
        ];
    }

    /**
     * Shared assembly tail: fail-fast on a duplicate type name, then cache and
     * build the ObjectType. Both build paths end here so naming + caching stay
     * in one place.
     *
     * @param array<string, mixed> $fields
     */
    private function assembleObjectType(string $class, string $name, array $fields): ObjectType
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
            $fields[$property->getName()] = $this->scalarField(
                $scalar,
                $type->allowsNull(),
                $property->getName(),
                null,
            );
        }

        return $this->assembleObjectType($outputClass, $reflection->getShortName(), $fields);
    }

    /**
     * Builds (and caches) a webonyx ObjectType from a Resource's canonical
     * metadata — the SAME `#[ResourceObject]` contract OpenAPI derives its
     * response schemas from. This is the single-source-of-truth path: change
     * the Resource, both REST and GraphQL update together. It supersedes the
     * ad-hoc reflection of an `#[ExposeAsGraphql(output: ...)]` DTO.
     *
     * Phase 1 scope: scalar + id fields only. Relation kinds (RefOne / RefMany
     * / Embedded* / Union) become traversable GraphQL object fields under
     * `ep-graphql-nested-resources`, not here — they are skipped, never
     * emitted as broken scalars.
     */
    public function forResource(ResourceObjectMetadata $meta): ?ObjectType
    {
        if (isset($this->types[$meta->class])) {
            return $this->types[$meta->class];
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
            $fields[$field->name] = $this->scalarField(
                $scalar,
                $field->nullable,
                $field->name,
                $field->description !== '' ? $field->description : null,
            );
        }

        if ($fields === []) {
            // A resource whose fields are all relations/unmapped yields no GraphQL
            // fields. webonyx rejects a fieldless ObjectType and would fatal the
            // ENTIRE schema, so degrade THIS field to the Json scalar (the caller
            // falls back) and surface it — rather than crash every operation.
            // Relation fields are tracked under ep-graphql-nested-resources.
            error_log(sprintf(
                '[semitexa-graphql] Resource %s exposes no scalar/id GraphQL fields; its '
                . 'field falls back to the Json scalar (relation fields are deferred).',
                $meta->class,
            ));

            return null;
        }

        // Name by class basename — identical to OpenAPI's component naming, so a
        // single #[ResourceObject] has one name across both surfaces.
        return $this->assembleObjectType($meta->class, $reflection->getShortName(), $fields);
    }

    /**
     * Test seam: allow tests to inject the scalar mapper without a container.
     * @internal
     */
    public function setScalarsForTest(ScalarTypeMapper $scalars): void
    {
        $this->scalars = $scalars;
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
