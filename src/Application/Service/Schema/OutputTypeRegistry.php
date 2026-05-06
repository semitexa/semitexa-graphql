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

    public function get(string $outputClass): ObjectType
    {
        if (isset($this->types[$outputClass])) {
            return $this->types[$outputClass];
        }

        $reflection = new ReflectionClass($outputClass);
        $shortName = $reflection->getShortName();

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
            $fieldType = $type->allowsNull() ? $scalar : Type::nonNull($scalar);
            $fieldName = $property->getName();

            $fields[$fieldName] = [
                'type' => $fieldType,
                'resolve' => static function (mixed $source, array $args, mixed $context, ResolveInfo $info) use ($fieldName): mixed {
                    return self::extractField($source, $fieldName);
                },
            ];
        }

        return $this->types[$outputClass] = new ObjectType([
            'name' => $shortName,
            'fields' => $fields,
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
