<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Domain\Contract;

use Semitexa\Graphql\Discovery\ResolvedGraphqlOperation;

/**
 * Resolves and invokes the Semitexa Handler bound to a given operation.
 *
 * The invoker is the bridge between GraphQL field resolution and the existing
 * Semitexa Payload-Handler-Resource pipeline. It MUST resolve handlers from
 * the application container (so DI properties are populated) and MUST construct
 * the Resource the handler's signature expects.
 *
 * The returned object is the unmodified Resource produced by the Handler's
 * `handle()` call — serialization to GraphQL output happens elsewhere.
 */
interface HandlerInvokerInterface
{
    public function invoke(ResolvedGraphqlOperation $operation, object $payload): object;
}
