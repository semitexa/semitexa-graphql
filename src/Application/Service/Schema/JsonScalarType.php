<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Application\Service\Schema;

use GraphQL\Language\AST\BooleanValueNode;
use GraphQL\Language\AST\FloatValueNode;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Language\AST\ListValueNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\NullValueNode;
use GraphQL\Language\AST\ObjectValueNode;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Type\Definition\ScalarType;

/**
 * Catch-all `Json` scalar used as a fallback when a GraphQL operation has
 * no `output:` class declared on `#[ExposeAsGraphql]`.
 *
 * Holds whatever shape the Resource serializer produced — typically a
 * primitive or an associative array. This is an escape hatch for opaque or
 * result-of-mutation responses (e.g. `deleteArticle` returning `{ deleted:
 * true }`); typed output classes remain the recommended path for
 * client-facing fields.
 */
final class JsonScalarType extends ScalarType
{
    public string $name = 'Json';
    public ?string $description = 'Opaque JSON value. Used when an operation declares no `output:` class on #[ExposeAsGraphql].';

    public function serialize(mixed $value): mixed
    {
        return $value;
    }

    public function parseValue(mixed $value): mixed
    {
        return $value;
    }

    public function parseLiteral(Node $valueNode, ?array $variables = null): mixed
    {
        return match (true) {
            $valueNode instanceof StringValueNode,
            $valueNode instanceof BooleanValueNode  => $valueNode->value,
            $valueNode instanceof IntValueNode      => (int) $valueNode->value,
            $valueNode instanceof FloatValueNode    => (float) $valueNode->value,
            $valueNode instanceof NullValueNode     => null,
            $valueNode instanceof ListValueNode     => array_map(
                fn (Node $n): mixed => $this->parseLiteral($n, $variables),
                iterator_to_array($valueNode->values->getIterator())
            ),
            $valueNode instanceof ObjectValueNode   => (function () use ($valueNode, $variables): array {
                $out = [];
                foreach ($valueNode->fields as $field) {
                    $out[$field->name->value] = $this->parseLiteral($field->value, $variables);
                }
                return $out;
            })(),
            default => null,
        };
    }
}
