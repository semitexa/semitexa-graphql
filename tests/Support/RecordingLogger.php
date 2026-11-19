<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Support;

use Semitexa\Core\Log\LoggerInterface;

/** Keeps what was logged, so a test can read the reason a client was not given. */
final class RecordingLogger implements LoggerInterface
{
    /** @var list<string> */
    public array $messages = [];

    public function error(string $message, array $context = []): void
    {
        $this->messages[] = $message;
    }

    public function critical(string $message, array $context = []): void
    {
        $this->messages[] = $message;
    }

    public function warning(string $message, array $context = []): void
    {
        $this->messages[] = $message;
    }

    public function info(string $message, array $context = []): void
    {
        $this->messages[] = $message;
    }

    public function notice(string $message, array $context = []): void
    {
        $this->messages[] = $message;
    }

    public function debug(string $message, array $context = []): void
    {
        $this->messages[] = $message;
    }
}
