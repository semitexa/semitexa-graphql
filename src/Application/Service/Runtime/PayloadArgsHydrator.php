<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Application\Service\Runtime;

use ReflectionClass;
use ReflectionNamedType;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Graphql\Domain\Contract\PayloadHydratorInterface;

/**
 * Default Payload hydrator for GraphQL.
 *
 * Mirrors the setter convention used by Core's HTTP `PayloadHydrator`: for
 * each `setX(<scalar>)` setter that matches a GraphQL argument name,
 * coerce the arg value to the setter's type and invoke. Unknown args are
 * ignored — the schema layer is the gate that decides which args are valid.
 */
#[SatisfiesServiceContract(of: PayloadHydratorInterface::class)]
final class PayloadArgsHydrator implements PayloadHydratorInterface
{
    public function hydrate(string $payloadClass, array $args): object
    {
        $reflection = new ReflectionClass($payloadClass);
        $instance = $reflection->newInstance();

        foreach ($args as $argName => $value) {
            $setterName = 'set' . ucfirst((string) $argName);
            if (!$reflection->hasMethod($setterName)) {
                continue;
            }
            $setter = $reflection->getMethod($setterName);
            if (!$setter->isPublic() || $setter->getNumberOfParameters() !== 1) {
                continue;
            }
            $param = $setter->getParameters()[0];
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType) {
                $value = $this->cast($value, $type);
            }
            $setter->invoke($instance, $value);
        }

        return $instance;
    }

    private function cast(mixed $value, ReflectionNamedType $type): mixed
    {
        $name = $type->getName();
        if ($value === null) {
            return $type->allowsNull() ? null : $this->zero($name);
        }
        return match ($name) {
            'int'    => is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0),
            'float'  => is_float($value) ? $value : (is_numeric($value) ? (float) $value : 0.0),
            'string' => is_scalar($value) ? (string) $value : '',
            'bool'   => is_bool($value) ? $value : (bool) $value,
            default  => $value,
        };
    }

    private function zero(string $name): mixed
    {
        return match ($name) {
            'int', 'float' => 0,
            'string'       => '',
            'bool'         => false,
            'array'        => [],
            default        => null,
        };
    }
}
