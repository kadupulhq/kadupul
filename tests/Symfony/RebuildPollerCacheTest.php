<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\Graphing\Application\Command\PollerCacheRebuild;
use Kadupul\Graphing\Application\Command\RebuildPollerCache;
use Kadupul\Graphing\Application\Port\PollerCacheOutcome;
use Kadupul\Graphing\Application\Port\PollerCacheRequest;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryPollerCache;
use PHPUnit\Framework\TestCase;

/** The rebuild_poller_cache.php use case: realm 15, audit, outcomes and the setting read. */
final class RebuildPollerCacheTest extends TestCase
{
    use ConsoleOperatorDatabase;

    private \Doctrine\DBAL\Connection $db;

    /** @var list<AuditEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->db = $this->operatorDatabase(8, 15);
    }

    private function useCase(InMemoryPollerCache $cache, bool $remote = false): RebuildPollerCache
    {
        $trail = $this->createStub(AuditTrail::class);
        $trail->method('record')->willReturnCallback(function (AuditEvent $event): void {
            $this->events[] = $event;
        });
        $topology = $this->createStub(CollectorTopology::class);
        $topology->method('isRemoteCollector')->willReturn($remote);
        $access = new GraphCommandAccess(new CliConsoleAccess($this->db, $this->db), $topology, $trail);

        return new RebuildPollerCache($access, $cache, $cache);
    }

    public function testItRunsTheRequestAndAuditsTheDevice(): void
    {
        $cache = new InMemoryPollerCache();
        $request = new PollerCacheRequest(hostId: '007', threads: 2);
        $result = ($this->useCase($cache))($request);

        self::assertTrue($result->succeeded());
        self::assertSame([PollerCacheOutcome::REBUILT, 2, OperatorDatabase::Local], [$result->status, $result->hosts, $result->database]);
        self::assertSame([$request], $cache->requests);
        self::assertCount(1, $this->events);
        self::assertSame(['poller-cache.rebuild', 'poller-cache', 'local:7', AuditEvent::SUCCEEDED], [$this->events[0]->action, $this->events[0]->targetType, $this->events[0]->targetId, $this->events[0]->outcome]);
    }

    public function testEveryDeviceIsAuditedAsAllAndARemoteCollectorWorksOnTheMainDatabase(): void
    {
        $cache = new InMemoryPollerCache();
        $result = ($this->useCase($cache, true))(new PollerCacheRequest());

        self::assertSame(OperatorDatabase::Main, $result->database);
        self::assertSame('main:all', $this->events[0]->targetId);
    }

    public function testTheProgressClosureReachesTheRebuilder(): void
    {
        $seen = [];
        $cache = new InMemoryPollerCache(printed: [['out', "WARNING\n"], ['err', 'oops']]);
        ($this->useCase($cache))(new PollerCacheRequest(), static function (string $type, string $text) use (&$seen): void {
            $seen[] = [$type, $text];
        });

        self::assertSame([['out', "WARNING\n"], ['err', 'oops']], $seen);
    }

    public function testAFailedWorkerIsReportedNotThrownAndAuditedAsFailed(): void
    {
        $result = ($this->useCase(new InMemoryPollerCache(outcome: null)))(new PollerCacheRequest());

        self::assertSame(PollerCacheRebuild::FAILED, $result->status);
        self::assertFalse($result->succeeded());
        self::assertSame(AuditEvent::FAILED, $this->events[0]->outcome);
    }

    public function testAnInterruptedRunFailsAndAnAlreadyRunningOneSucceeds(): void
    {
        $interrupted = ($this->useCase(new InMemoryPollerCache(new PollerCacheOutcome(PollerCacheOutcome::INTERRUPTED))))(new PollerCacheRequest());
        $running = ($this->useCase(new InMemoryPollerCache(new PollerCacheOutcome(PollerCacheOutcome::ALREADY_RUNNING))))(new PollerCacheRequest());

        self::assertFalse($interrupted->succeeded());
        self::assertTrue($running->succeeded());
        self::assertSame([AuditEvent::FAILED, AuditEvent::SUCCEEDED], array_map(static fn(AuditEvent $event): string => $event->outcome, $this->events));
    }

    public function testDevicesAloneAreNotEnoughBecauseTheWebPageIsUtilitiesPhp(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 15');
        $this->db->executeStatement('INSERT INTO user_auth_realm (user_id, realm_id) VALUES (1, 3)');
        $cache = new InMemoryPollerCache();

        foreach ([fn() => ($this->useCase($cache))(new PollerCacheRequest()), fn() => $this->useCase($cache)->concurrentProcesses()] as $attempt) {
            try {
                $attempt();
                self::fail('The operator without the Utilities realm was admitted.');
            } catch (GraphManagementDenied) {
                self::assertTrue(true);
            }
        }
        self::assertSame([], $cache->requests);
        self::assertSame(0, $cache->settingReads, 'the setting is not read for a refused operator');
        self::assertSame([AuditEvent::DENIED, AuditEvent::DENIED], array_map(static fn(AuditEvent $event): string => $event->decision, $this->events));
    }

    public function testTheSettingIsReadForAnAuthorizedOperatorAndSignalsArePassedOn(): void
    {
        $cache = new InMemoryPollerCache(running: true);
        $useCase = $this->useCase($cache);

        self::assertSame('4', $useCase->concurrentProcesses());
        self::assertTrue($useCase->interrupt(15));
        self::assertSame([15], $cache->signals);
    }

    public function testAnOutcomeTheWorkerMayNotReportIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PollerCacheOutcome('done');
    }
}
