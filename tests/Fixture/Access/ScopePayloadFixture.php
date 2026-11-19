<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture\Access;

use Semitexa\Api\Attribute\RequiresScope;
use Semitexa\Authorization\Attribute\AsServicePayload;
use Semitexa\Core\Exception\ValidationException;
use Semitexa\Graphql\Attribute\ExposeAsGraphql;
use Semitexa\Graphql\Tests\Fixture\Runtime\RuntimeOutputFixture;

/** A machine credential that holds the scope orders.read. */
#[AsServicePayload(path: '/access/scope/{id}', methods: ['GET'])]
#[RequiresScope('orders.read')]
#[ExposeAsGraphql(field: 'scopeThing', rootType: 'query', output: RuntimeOutputFixture::class)]
final class ScopePayloadFixture
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
