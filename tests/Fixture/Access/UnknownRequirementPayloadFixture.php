<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture\Access;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Exception\ValidationException;
use Semitexa\Graphql\Attribute\ExposeAsGraphql;
use Semitexa\Graphql\Tests\Fixture\Runtime\RuntimeOutputFixture;

/** Public, with a requirement nothing here knows how to enforce. */
#[AsPublicPayload(path: '/access/moon/{id}', methods: ['GET'])]
#[RequiresMoonPhaseFixture]
#[ExposeAsGraphql(field: 'moonThing', rootType: 'query', output: RuntimeOutputFixture::class)]
final class UnknownRequirementPayloadFixture
{
    private string $id = '';

    public function getId(): string { return $this->id; }

    public function setId(string $id): void
    {
        if ($id === '') {
            throw new ValidationException(['id' => ['id must not be empty']]);
        }
        $this->id = $id;
    }
}
