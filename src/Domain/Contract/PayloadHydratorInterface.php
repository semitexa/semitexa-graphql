<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Domain\Contract;

/**
 * Hydrates a Semitexa Payload DTO from a GraphQL field's resolved arguments.
 *
 * Implementations receive an array of pre-coerced GraphQL arg values (already
 * shaped by the schema's argument types) and must return a fully populated
 * Payload instance. Validation belongs to the Payload's setters: any
 * `setX()` may throw `Semitexa\Core\Exception\ValidationException` and the
 * exception bubbles up through hydration unchanged.
 */
interface PayloadHydratorInterface
{
    /**
     * @template T of object
     * @param class-string<T>      $payloadClass FQCN of the Semitexa Payload DTO to hydrate.
     * @param array<string, mixed> $args         GraphQL argument map: arg name → coerced value.
     * @return T
     */
    public function hydrate(string $payloadClass, array $args): object;
}
