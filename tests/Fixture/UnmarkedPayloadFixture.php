<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Fixture;

/** Has no #[ExposeAsGraphql] — must be skipped by the registry. */
final class UnmarkedPayloadFixture {}
