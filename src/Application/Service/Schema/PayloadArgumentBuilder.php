<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Application\Service\Schema;

use GraphQL\Type\Definition\Type;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;

/**
 * Derives a GraphQL field's argument map from a Semitexa Payload DTO.
 *
 * Convention follows the existing Core PayloadHydrator: each `public function
 * setX(<scalar>): void` setter on the Payload becomes one GraphQL field
 * argument named `x` (lowercase first letter). Type and nullability are
 * inferred from the setter parameter's declared type.
 *
 * Setters with non-scalar parameters are skipped — they remain reachable via
 * REST hydration but are not part of the GraphQL surface (input objects and
 * nested arguments are tracked under `ep-graphql-nested-resources`).
 */
#[AsService]
final class PayloadArgumentBuilder
{
    #[InjectAsReadonly]
    protected ScalarTypeMapper $scalars;

    /** @return array<string, array{type: \GraphQL\Type\Definition\Type, description?: string}> */
    public function buildFor(string $payloadClass): array
    {
        $reflection = new ReflectionClass($payloadClass);

        $args = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (!str_starts_with($method->getName(), 'set')) {
                continue;
            }
            if ($method->getNumberOfParameters() !== 1) {
                continue;
            }
            $param = $method->getParameters()[0];
            $type = $param->getType();
            if (!$type instanceof ReflectionNamedType) {
                continue;
            }

            $argName = lcfirst(substr($method->getName(), 3));
            if ($argName === '') {
                continue;
            }

            $scalar = $this->scalars->mapForArgument($type->getName(), $argName);
            if ($scalar === null) {
                continue;
            }

            // ID arguments default to NonNull because callers must provide them.
            // Other scalars default to nullable so a Payload only needs to set
            // the fields the client actually supplied — matches REST behavior.
            $isId = $scalar === Type::id();
            $args[$argName] = [
                'type' => $isId ? Type::nonNull($scalar) : $scalar,
            ];
        }

        return $args;
    }

    /**
     * Test seam: allow tests to inject the scalar mapper without a container.
     * @internal
     */
    public function setScalarsForTest(ScalarTypeMapper $scalars): void
    {
        $this->scalars = $scalars;
    }
}
