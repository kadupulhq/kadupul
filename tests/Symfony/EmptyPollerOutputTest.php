<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Command\EmptyPollerOutput;
use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\Graphing\Application\Port\PollerOutputDrainResult;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryPollerOutputDrain;
use PHPUnit\Framework\TestCase;

final class EmptyPollerOutputTest extends TestCase
{
    use ConsoleOperatorDatabase;

    private \Doctrine\DBAL\Connection $db;

    /** @var list<AuditEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->db = $this->operatorDatabase(8, 3);
    }

    private function useCase(InMemoryPollerOutputDrain $drain, bool $remoteCollector = false): EmptyPollerOutput
    {
        $trail = $this->createStub(AuditTrail::class);
        $trail->method('record')->willReturnCallback(function (AuditEvent $event): void {
            $this->events[] = $event;
        });
        $topology = $this->createStub(CollectorTopology::class);
        $topology->method('isRemoteCollector')->willReturn($remoteCollector);

        return new EmptyPollerOutput(new GraphCommandAccess(new CliConsoleAccess($this->db, $this->db), $topology, $trail), $drain);
    }

    public function testItEmptiesTheQueueAndAuditsTheSuccess(): void
    {
        $drain = new InMemoryPollerOutputDrain();
        $result = ($this->useCase($drain))(null);

        self::assertTrue($result->succeeded());
        self::assertSame([3, PollerOutputDrainResult::OK, OperatorDatabase::Local], [$result->updates, $result->status, $result->database]);
        self::assertSame(1, $drain->drained);
        self::assertCount(1, $this->events);
        self::assertSame(['poller-output.empty', 'poller-output', 'local:queue', AuditEvent::SUCCEEDED], [$this->events[0]->action, $this->events[0]->targetType, $this->events[0]->targetId, $this->events[0]->outcome]);
    }

    public function testARemoteCollectorDrainsItsOwnQueueAsTheOriginalNeverLeftItsDatabase(): void
    {
        $drain = new InMemoryPollerOutputDrain();
        $result = ($this->useCase($drain, true))(null);

        self::assertSame(OperatorDatabase::Local, $result->database);
        self::assertSame('local:queue', $this->events[0]->targetId);
    }

    /** @return iterable<string, array{string}> */
    public static function refusals(): iterable
    {
        yield 'rrdtool could not start' => [PollerOutputDrainResult::RRD_UNAVAILABLE];
        yield 'the queue could not be counted' => [PollerOutputDrainResult::COUNT_UNAVAILABLE];
        yield 'a pass made no progress' => [PollerOutputDrainResult::NO_PROGRESS];
        yield 'the worker failed' => [PollerOutputDrainResult::FAILED];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testAPassThatDoesNotEmptyTheQueueIsReportedAndAuditedAsFailed(string $status): void
    {
        $result = ($this->useCase(new InMemoryPollerOutputDrain(new PollerOutputDrainResult($status))))(null);

        self::assertFalse($result->succeeded());
        self::assertSame($status, $result->status);
        self::assertSame([AuditEvent::FAILED], array_map(static fn(AuditEvent $event): string => $event->outcome, $this->events));
    }

    public function testAnUnknownOutcomeIsAFailureNotAnException(): void
    {
        $result = ($this->useCase(new InMemoryPollerOutputDrain(fails: true)))(null);

        self::assertSame(PollerOutputDrainResult::FAILED, $result->status);
        self::assertSame([AuditEvent::FAILED, 'local:queue'], [$this->events[0]->outcome, $this->events[0]->targetId]);
    }

    public function testWhatThePassPrintsReachesTheCaller(): void
    {
        $seen = [];
        $drain = new InMemoryPollerOutputDrain(printed: [['out', "OK u:0.01\n"], ['err', 'warning']]);
        ($this->useCase($drain))(null, static function (string $stream, string $text) use (&$seen): void {
            $seen[] = [$stream, $text];
        });

        self::assertSame([['out', "OK u:0.01\n"], ['err', 'warning']], $seen);
    }

    public function testAnOperatorWithoutTheDevicesRealmIsRefusedBeforeTheQueueIsTouched(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 3');
        $this->db->executeStatement('INSERT INTO user_auth_realm (user_id, realm_id) VALUES (1, 5)');
        $drain = new InMemoryPollerOutputDrain();

        try {
            ($this->useCase($drain))(null);
            self::fail('The operator without the devices realm was admitted.');
        } catch (GraphManagementDenied) {
            self::assertSame(0, $drain->drained);
            self::assertSame([AuditEvent::DENIED], array_map(static fn(AuditEvent $event): string => $event->decision, $this->events));
        }
    }

    public function testAnEmptyOperatorNameIsRefusedAndAnUnknownOneToo(): void
    {
        foreach (['', 'nobody'] as $operator) {
            $drain = new InMemoryPollerOutputDrain();
            try {
                ($this->useCase($drain))($operator);
                self::fail('Operator "' . $operator . '" was admitted.');
            } catch (GraphManagementDenied) {
                self::assertSame(0, $drain->drained);
            }
        }
    }
}
