<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Pipeline;

use GraphQL\Error\Error;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Exception\AccessDeniedException;
use Semitexa\Core\Exception\AuthenticationException;
use Semitexa\Core\Exception\NotFoundException;
use Semitexa\Core\Exception\RateLimitException;
use Semitexa\Core\Exception\ValidationException;
use Throwable;

/**
 * Maps a webonyx GraphQL error (which wraps a thrown PHP exception) to the
 * Semitexa-friendly response array shape.
 *
 * Conventions:
 *   - GraphQL syntax / validation errors (no underlying exception) keep
 *     their default message but get `extensions.code = 'GRAPHQL_VALIDATION'`.
 *   - Semitexa domain exceptions are mapped to a stable code plus structured
 *     `extensions` (e.g. `errors` for ValidationException's field map).
 *   - Anything else (resolver-thrown library errors, infrastructure faults)
 *     becomes `INTERNAL_SERVER_ERROR` with a generic message — we never leak
 *     stack traces or original exception messages to GraphQL clients.
 */
#[AsService]
final class GraphqlErrorMapper
{
    /**
     * @return array<string, mixed>
     */
    public function mapError(Error $error): array
    {
        $previous = $error->getPrevious();

        if ($previous instanceof Throwable) {
            $mapped = $this->mapDomainException($previous);
            $mapped['locations'] = $this->locations($error);
            $path = $error->getPath();
            if ($path !== []) {
                $mapped['path'] = $path;
            }
            return $mapped;
        }

        // No underlying exception → this is a webonyx-level error
        // (parse / validation / coerce variable). Surface its message and
        // stamp a stable extension code so clients can branch on it.
        $payload = [
            'message' => $error->getMessage(),
            'extensions' => ['code' => 'GRAPHQL_VALIDATION'],
        ];
        $payload['locations'] = $this->locations($error);
        $path = $error->getPath();
        if ($path !== []) {
            $payload['path'] = $path;
        }
        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapDomainException(Throwable $e): array
    {
        return match (true) {
            $e instanceof ValidationException => [
                'message' => $e->getMessage(),
                'extensions' => [
                    'code' => 'VALIDATION_FAILED',
                    'http_status' => 422,
                    'errors' => $e->getErrorContext()['errors'] ?? [],
                ],
            ],
            $e instanceof NotFoundException => [
                'message' => $e->getMessage(),
                'extensions' => ['code' => 'NOT_FOUND', 'http_status' => 404],
            ],
            $e instanceof AccessDeniedException => [
                'message' => $e->getMessage(),
                'extensions' => ['code' => 'FORBIDDEN', 'http_status' => 403],
            ],
            $e instanceof AuthenticationException => [
                'message' => $e->getMessage(),
                'extensions' => ['code' => 'UNAUTHENTICATED', 'http_status' => 401],
            ],
            $e instanceof RateLimitException => [
                'message' => $e->getMessage(),
                'extensions' => ['code' => 'RATE_LIMITED', 'http_status' => 429],
            ],
            default => [
                'message' => 'Internal server error.',
                'extensions' => ['code' => 'INTERNAL_SERVER_ERROR', 'http_status' => 500],
            ],
        };
    }

    /**
     * @return list<array{line: int, column: int}>
     */
    private function locations(Error $error): array
    {
        $locations = [];
        foreach ($error->getLocations() as $location) {
            $locations[] = ['line' => $location->line, 'column' => $location->column];
        }
        return $locations;
    }
}
