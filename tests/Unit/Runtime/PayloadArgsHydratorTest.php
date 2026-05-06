<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Semitexa\Graphql\Application\Service\Runtime\PayloadArgsHydrator;
use Semitexa\Graphql\Tests\Fixture\Runtime\RuntimePayloadFixture;

final class PayloadArgsHydratorTest extends TestCase
{
    public function test_hydrates_known_setters_with_coerced_values(): void
    {
        $hydrator = new PayloadArgsHydrator();

        /** @var RuntimePayloadFixture $payload */
        $payload = $hydrator->hydrate(
            RuntimePayloadFixture::class,
            ['id' => 'abc', 'limit' => 25],
        );

        self::assertSame('abc', $payload->getId());
        self::assertSame(25, $payload->getLimit());
    }

    public function test_unknown_args_are_silently_ignored(): void
    {
        $hydrator = new PayloadArgsHydrator();

        /** @var RuntimePayloadFixture $payload */
        $payload = $hydrator->hydrate(
            RuntimePayloadFixture::class,
            ['id' => 'abc', 'mystery' => 'value'],
        );

        self::assertSame('abc', $payload->getId());
    }

    public function test_string_id_remains_a_string_even_if_passed_as_a_number(): void
    {
        $hydrator = new PayloadArgsHydrator();

        /** @var RuntimePayloadFixture $payload */
        $payload = $hydrator->hydrate(
            RuntimePayloadFixture::class,
            ['id' => 42],
        );

        self::assertSame('42', $payload->getId());
    }

    public function test_int_setter_coerces_string_input(): void
    {
        $hydrator = new PayloadArgsHydrator();

        /** @var RuntimePayloadFixture $payload */
        $payload = $hydrator->hydrate(
            RuntimePayloadFixture::class,
            ['id' => 'x', 'limit' => '99'],
        );

        self::assertSame(99, $payload->getLimit());
    }
}
