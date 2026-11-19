<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture\Access;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Graphql\Attribute\ExposeAsGraphql;
use Semitexa\Graphql\Tests\Fixture\Runtime\RuntimeOutputFixture;

/**
 * Public, with a rule no single setter can check: each value is fine alone,
 * the pair is not. Only validate() can refuse it.
 */
#[AsPublicPayload(path: '/access/range', methods: ['GET'])]
#[ExposeAsGraphql(field: 'rangeThing', rootType: 'query', output: RuntimeOutputFixture::class)]
final class ValidatedPayloadFixture implements ValidatablePayloadInterface
{
    private int $from = 0;
    private int $to = 0;

    public function getId(): string { return $this->from . '-' . $this->to; }

    public function setFrom(int $from): void { $this->from = $from; }

    public function setTo(int $to): void { $this->to = $to; }

    public function validate(): array
    {
        return $this->to < $this->from ? ['to' => ['to must not be before from']] : [];
    }
}
