<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\Graphing\Application\Command\ReplayRejectedSamples;
use Kadupul\Graphing\Application\Port\RejectedSampleReplayResult;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryRejectedSampleReplayer;
use PHPUnit\Framework\TestCase;

final class ReplayRejectedSamplesTest extends TestCase
{
    use ConsoleOperatorDatabase;

    private \Doctrine\DBAL\Connection $db;

    /** @var list<AuditEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->db = $this->operatorDatabase(8, 3);
    }

    private function useCase(InMemoryRejectedSampleReplayer $replayer, bool $remoteCollector = false): ReplayRejectedSamples
    {
        $trail = $this->createStub(AuditTrail::class);
        $trail->method('record')->willReturnCallback(function (AuditEvent $event): void {
            $this->events[] = $event;
        });
        $topology = $this->createStub(CollectorTopology::class);
        $topology->method('isRemoteCollector')->willReturn($remoteCollector);

        return new ReplayRejectedSamples(new GraphCommandAccess(new CliConsoleAccess($this->db, $this->db), $topology, $trail), $replayer);
    }

    public function testItReplaysOneDataSourceAndAuditsIt(): void
    {
        $replayer = new InMemoryRejectedSampleReplayer();
        $result = ($this->useCase($replayer))(null, 7, false);

        self::assertTrue($result->succeeded());
        self::assertSame([5, 7, false, OperatorDatabase::Local], [$result->samples, $result->localDataId, $result->dryRun, $result->database]);
        self::assertSame([[7, false]], $replayer->calls);
        self::assertCount(1, $this->events);
        self::assertSame(['rejected-samples.replay', 'rejected-samples', 'local:7', AuditEvent::SUCCEEDED], [$this->events[0]->action, $this->events[0]->targetType, $this->events[0]->targetId, $this->events[0]->outcome]);
    }

    public function testItReplaysEveryDataSource(): void
    {
        $replayer = new InMemoryRejectedSampleReplayer();
        ($this->useCase($replayer))(null, null, false);

        self::assertSame([[null, false]], $replayer->calls);
        self::assertSame('local:all', $this->events[0]->targetId);
    }

    public function testADryRunMovesNothingAndRecordsNothing(): void
    {
        $replayer = new InMemoryRejectedSampleReplayer();
        $result = ($this->useCase($replayer))(null, null, true);

        self::assertTrue($result->dryRun);
        self::assertSame([[null, true]], $replayer->calls);
        self::assertSame([], $this->events);
    }

    public function testARemoteCollectorReplaysItsOwnQueueAsTheOriginalNeverLeftItsDatabase(): void
    {
        $result = ($this->useCase(new InMemoryRejectedSampleReplayer(), true))(null, null, false);

        self::assertSame(OperatorDatabase::Local, $result->database);
        self::assertSame('local:all', $this->events[0]->targetId);
    }

    /** @return iterable<string, array{RejectedSampleReplayResult}> */
    public static function refusals(): iterable
    {
        yield 'an online remote collector' => [new RejectedSampleReplayResult(RejectedSampleReplayResult::REMOTE_COLLECTOR)];
        yield 'a volatile queue' => [new RejectedSampleReplayResult(RejectedSampleReplayResult::UNSAFE_QUEUE, 0, 'The poller_output queue must use InnoDB.')];
        yield 'a failed move' => [new RejectedSampleReplayResult(RejectedSampleReplayResult::FAILED)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testARunThatMovesNothingIsReportedAndAuditedAsFailed(RejectedSampleReplayResult $answer): void
    {
        $result = ($this->useCase(new InMemoryRejectedSampleReplayer($answer)))(null, null, false);

        self::assertFalse($result->succeeded());
        self::assertSame([$answer->status, $answer->reason], [$result->status, $result->reason]);
        self::assertSame([AuditEvent::FAILED], array_map(static fn(AuditEvent $event): string => $event->outcome, $this->events));
    }

    public function testAnUnknownOutcomeIsAFailureNotAnException(): void
    {
        $result = ($this->useCase(new InMemoryRejectedSampleReplayer(fails: true)))(null, 7, false);

        self::assertSame(RejectedSampleReplayResult::FAILED, $result->status);
        self::assertSame([AuditEvent::FAILED, 'local:7'], [$this->events[0]->outcome, $this->events[0]->targetId]);
    }

    public function testWhatTheRunPrintsReachesTheCaller(): void
    {
        $seen = [];
        $replayer = new InMemoryRejectedSampleReplayer(printed: [['out', "line\n"], ['err', 'warning']]);
        ($this->useCase($replayer))(null, null, true, static function (string $stream, string $text) use (&$seen): void {
            $seen[] = [$stream, $text];
        });

        self::assertSame([['out', "line\n"], ['err', 'warning']], $seen);
    }

    /** @return iterable<string, array{bool}> */
    public static function modes(): iterable
    {
        yield 'a run' => [false];
        yield 'a dry run' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function testAnOperatorWithoutTheDevicesRealmIsRefusedBeforeAnythingIsRead(bool $dryRun): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 3');
        $this->db->executeStatement('INSERT INTO user_auth_realm (user_id, realm_id) VALUES (1, 5)');
        $replayer = new InMemoryRejectedSampleReplayer();

        try {
            ($this->useCase($replayer))(null, null, $dryRun);
            self::fail('The operator without the devices realm was admitted.');
        } catch (GraphManagementDenied) {
            self::assertSame([], $replayer->calls);
            self::assertSame([AuditEvent::DENIED], array_map(static fn(AuditEvent $event): string => $event->decision, $this->events));
            // A refused dry run is recorded under the dry-run action so a query for refusals can tell them apart.
            self::assertSame($dryRun ? 'rejected-samples.replay.dry-run' : 'rejected-samples.replay', $this->events[0]->action);
        }
    }

    public function testAnEmptyOperatorNameIsRefusedAndAnUnknownOneToo(): void
    {
        foreach (['', 'nobody'] as $operator) {
            $replayer = new InMemoryRejectedSampleReplayer();
            try {
                ($this->useCase($replayer))($operator, null, false);
                self::fail('Operator "' . $operator . '" was admitted.');
            } catch (GraphManagementDenied) {
                self::assertSame([], $replayer->calls);
            }
        }
    }
}
