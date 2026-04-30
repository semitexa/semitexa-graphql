<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture\Runtime;

use Semitexa\Core\Contract\ValidatablePayload;
use Semitexa\Core\Http\PayloadValidationResult;
use Semitexa\Graphql\Attribute\ExposeAsGraphql;

/**
 * Self-contained Payload fixture exercised by Runtime tests.
 * `setId` produces an `ID!` argument; `setLimit` an optional `Int`.
 *
 * `validate()` rejects an empty `id` so the Runtime tests can drive both the
 * happy path and the ValidationException path without depending on Core's
 * full HTTP pipeline.
 */
#[ExposeAsGraphql(
    field: 'runtimeFixture',
    rootType: 'query',
    output: RuntimeOutputFixture::class,
    description: 'Runtime test fixture.',
)]
final class RuntimePayloadFixture implements ValidatablePayload
{
    private string $id = '';
    private int $limit = 10;

    public function getId(): string { return $this->id; }
    public function setId(string $id): void { $this->id = trim($id); }

    public function getLimit(): int { return $this->limit; }
    public function setLimit(int $limit): void { $this->limit = $limit; }

    public function validate(): PayloadValidationResult
    {
        if ($this->id === '') {
            return new PayloadValidationResult(false, ['id' => ['id must not be empty']]);
        }
        return new PayloadValidationResult(true, []);
    }
}
