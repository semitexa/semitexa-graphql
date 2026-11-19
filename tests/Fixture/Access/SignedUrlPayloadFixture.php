<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture\Access;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Attribute\RequiresSignedUrl;
use Semitexa\Core\Exception\ValidationException;
use Semitexa\Graphql\Attribute\ExposeAsGraphql;
use Semitexa\Graphql\Tests\Fixture\Runtime\RuntimeOutputFixture;

/** Public, but only through a signed link: a GraphQL call has no such link. */
#[AsPublicPayload(path: '/access/signed/{id}', methods: ['GET'])]
#[RequiresSignedUrl]
#[ExposeAsGraphql(field: 'signedThing', rootType: 'query', output: RuntimeOutputFixture::class)]
final class SignedUrlPayloadFixture
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
