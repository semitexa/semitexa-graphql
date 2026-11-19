<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture\Access;

use Semitexa\Graphql\Tests\Fixture\Runtime\RuntimeOutputFixture;
use Semitexa\Graphql\Tests\Fixture\Runtime\RuntimeResourceFixture;

/**
 * The one handler behind every access fixture. It counts its calls: a refused
 * operation is proved by the handler never having run, not only by the error.
 */
final class AccessHandlerFixture
{
    public static int $calls = 0;

    public function handle(object $payload, RuntimeResourceFixture $resource): RuntimeResourceFixture
    {
        self::$calls++;
        $id = method_exists($payload, 'getId') ? $payload->getId() : '';
        $id = is_string($id) ? $id : '';

        return $resource->with('data', new RuntimeOutputFixture(
            id: $id,
            name: 'secret-' . $id,
            count: 1,
            enabled: true,
            score: 1.0,
        ));
    }
}
