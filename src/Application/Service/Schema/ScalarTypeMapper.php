<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Application\Service\Schema;

use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\Type;
use Semitexa\Core\Attribute\AsService;

/**
 * Maps PHP scalar type names to webonyx scalar types.
 *
 * The mapping is intentionally narrow: only the GraphQL spec scalars plus
 * `ID` for primary identifiers. Unknown types fall through to `null` so the
 * caller can decide whether to skip the field, throw, or wrap with a custom
 * scalar.
 *
 * Identifier setters (`setId`, `setSlug`, `setUuid`) are typed as `ID`
 * regardless of the underlying PHP type — that's the GraphQL convention and
 * keeps query examples readable (`articleById(id: "gql_1")` not
 * `articleById(id: gql_1)` for an int).
 */
#[AsService]
final class ScalarTypeMapper
{
    /** Field names that should always be exposed as `ID`, not `String`. */
    private const ID_FIELDS = [
        'id'   => true,
        'uuid' => true,
        'slug' => true,
    ];

    public function mapForArgument(string $phpType, string $argName): ?ScalarType
    {
        if (isset(self::ID_FIELDS[$argName])) {
            return Type::id();
        }
        return $this->mapPhpType($phpType);
    }

    public function mapForOutputField(string $phpType, string $fieldName): ?ScalarType
    {
        if (isset(self::ID_FIELDS[$fieldName])) {
            return Type::id();
        }
        return $this->mapPhpType($phpType);
    }

    private function mapPhpType(string $phpType): ?ScalarType
    {
        return match ($phpType) {
            'string' => Type::string(),
            'int'    => Type::int(),
            'float'  => Type::float(),
            'bool'   => Type::boolean(),
            default  => null,
        };
    }
}
