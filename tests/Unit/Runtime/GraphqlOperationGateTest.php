<?php

declare(strict_types=1);

namespace Semitexa\Graphql\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Exception\AccessDeniedException;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Graphql\Application\Service\Runtime\GraphqlOperationGate;
use Semitexa\Graphql\Discovery\ResolvedGraphqlOperation;
use Semitexa\Graphql\Tests\Fixture\Access\ProtectedChildPayloadFixture;
use Semitexa\Graphql\Tests\Fixture\Access\PublicParentPayloadFixture;
use Semitexa\Graphql\Tests\Fixture\Runtime\RuntimeResourceFixture;
use Semitexa\Graphql\Tests\Support\AccessHarness;
use Semitexa\Graphql\Tests\Support\RecordingLogger;

/**
 * The gate on its own, unwired: an in-process execution with no container and
 * no request, where only what the payload class declares decides.
 */
final class GraphqlOperationGateTest extends TestCase
{
    private RecordingLogger $log;

    private ?LoggerInterface $savedLogger = null;

    protected function setUp(): void
    {
        $this->savedLogger = AccessHarness::takeLogger();
        StaticLoggerBridge::set($this->log = new RecordingLogger());
    }

    protected function tearDown(): void
    {
        AccessHarness::restoreLogger($this->savedLogger);
    }

    /**
     * `#[AsPublicPayload]` anywhere up the chain used to make the class public,
     * while the policy resolver lets the nearest class decide: in-process,
     * where nothing else is asked, a protected child of a public parent ran.
     */
    public function test_a_protected_child_of_a_public_parent_is_not_public(): void
    {
        /** @var \ArrayObject<int, true> $hydrated */
        $hydrated = new \ArrayObject();

        try {
            (new GraphqlOperationGate())->admit(
                self::operation(ProtectedChildPayloadFixture::class),
                static function () use ($hydrated): object {
                    $hydrated->append(true);

                    return new ProtectedChildPayloadFixture();
                },
            );
            self::fail('a protected child of a public parent must be refused');
        } catch (AccessDeniedException $e) {
            self::assertSame(GraphqlOperationGate::UNAVAILABLE, $e->getMessage());
        }

        self::assertCount(0, $hydrated, 'refused before its arguments are hydrated');
        self::assertStringContainsString(ProtectedChildPayloadFixture::class, implode("\n", $this->log->messages));
    }

    public function test_the_public_parent_itself_still_runs_in_process(): void
    {
        $payload = (new GraphqlOperationGate())->admit(
            self::operation(PublicParentPayloadFixture::class),
            static fn (): object => new PublicParentPayloadFixture(),
        );

        self::assertInstanceOf(PublicParentPayloadFixture::class, $payload);
        self::assertSame([], $this->log->messages);
    }

    private static function operation(string $payloadClass): ResolvedGraphqlOperation
    {
        return new ResolvedGraphqlOperation(
            field: 'childThing',
            rootType: 'query',
            payloadClass: $payloadClass,
            outputClass: null,
            routeName: 'access.child',
            path: '/access/child',
            httpMethods: ['GET'],
            handlerClasses: [],
            responseClass: RuntimeResourceFixture::class,
            description: '',
        );
    }
}
