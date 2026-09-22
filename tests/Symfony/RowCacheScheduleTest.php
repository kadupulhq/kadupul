<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Application\Command\CleanInvalidatedRowCache;
use Kadupul\IdentityAccess\Application\Port\InvalidatedRowCache;
use Kadupul\IdentityAccess\Domain\RowCacheInvalidation;
use Kadupul\IdentityAccess\Infrastructure\Legacy\InstallationRowCache;
use Kadupul\IdentityAccess\Infrastructure\Symfony\RowCacheCleanup;
use Kadupul\IdentityAccess\Infrastructure\Symfony\RowCacheCleanupHandler;
use Kadupul\IdentityAccess\Infrastructure\Symfony\RowCacheSchedule;
use Kadupul\IdentityAccess\Infrastructure\Symfony\RowCacheState;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Scheduler\Generator\MessageGenerator;

final class RowCacheScheduleTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kadupul-schedule-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    private function provider(?string $enabled = '1'): RowCacheSchedule
    {
        $config = $this->createMock(LegacyConfiguration::class);
        $config->expects($enabled === '1' ? self::once() : self::never())->method('values')->willReturn([]);

        return new RowCacheSchedule($config, new RowCacheState($this->directory), $enabled);
    }

    public function testDisabledScheduleDoesNotReadInstallationOrCreateState(): void
    {
        foreach ([null, '', '0', 'true'] as $disabled) {
            self::assertSame([], $this->provider($disabled)->getSchedule()->getRecurringMessages());
        }
        self::assertDirectoryDoesNotExist($this->directory);
    }

    public function testRemoteOrInvalidInstallationCannotSchedule(): void
    {
        $config = $this->createMock(LegacyConfiguration::class);
        $config->method('values')->willThrowException(new \RuntimeException('Primary installation required.'));
        $this->expectException(\RuntimeException::class);
        (new RowCacheSchedule($config, new RowCacheState($this->directory), '1'))->getSchedule();
    }

    public function testRestartSkipsBacklogAndLockExcludesSecondWorker(): void
    {
        $clock = new MockClock('2026-09-21 00:00:00 UTC');
        $first = $this->provider();
        $generator = new MessageGenerator($first, 'row_cache', $clock);
        self::assertSame([], iterator_to_array($generator->getMessages(), false));
        $clock->sleep(301);
        self::assertCount(1, iterator_to_array($generator->getMessages(), false));
        $second = $this->provider();
        $replacement = new MessageGenerator($second, 'row_cache', $clock);
        $clock->sleep(1801);
        self::assertSame([], iterator_to_array($replacement->getMessages(), false));
        $first->getSchedule()->getLock()->release();
        // Symfony 7.4 can defer a resumed, already-checkpointed periodic
        // schedule to its next tick. Cleanup must not depend on an immediate run.
        self::assertSame([], iterator_to_array($replacement->getMessages(), false));
        $clock->sleep(300);
        $messages = iterator_to_array($replacement->getMessages(), false);
        self::assertCount(1, $messages);
        self::assertInstanceOf(RowCacheCleanup::class, $messages[0]);
        $clock->sleep(1801);
        self::assertCount(1, iterator_to_array($replacement->getMessages(), false));
        $second->getSchedule()->getLock()->release();
    }

    private function database(): DatabaseConnection
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->sqliteCreateFunction('FROM_UNIXTIME', static fn(string $time): int => (int) $time);
        $pdo->exec('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)');
        $pdo->exec('CREATE TABLE user_auth_row_cache (user_id INTEGER, class TEXT, hash TEXT, time INTEGER, PRIMARY KEY (user_id, class, hash))');

        return new class ($pdo) implements DatabaseConnection {
            public function __construct(private readonly \PDO $pdo) {}

            public function get(): \PDO
            {
                return $this->pdo;
            }
        };
    }

    public function testCleanupIsBoundedIdempotentAndPreservesFreshAndOtherClasses(): void
    {
        $db = $this->database();
        $pdo = $db->get();
        $pdo->exec("INSERT INTO settings VALUES ('time_last_change_graph', '100'), ('time_last_changeXgraph', '999')");
        $insert = $pdo->prepare('INSERT INTO user_auth_row_cache VALUES (?, ?, ?, ?)');
        for ($i = 0; $i < 1001; ++$i) {
            $insert->execute([$i, 'graph', 'stale', 99]);
        }
        $insert->execute([1, 'graph', 'boundary', 100]);
        $insert->execute([1, 'graph', 'fresh', 101]);
        $insert->execute([1, 'device', 'other', 1]);
        $storage = new InstallationRowCache($db);
        self::assertSame(1001, $storage->count(new RowCacheInvalidation('graph', 100)));
        $clean = new CleanInvalidatedRowCache($storage);
        self::assertSame(1000, $clean());
        self::assertSame(1, $clean());
        self::assertSame(0, $clean());
        self::assertSame(0, $storage->count(new RowCacheInvalidation('graph', 100)));
        self::assertSame(['boundary', 'fresh', 'other'], $pdo->query('SELECT hash FROM user_auth_row_cache ORDER BY hash')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testConcurrentRefreshSurvivesSelectedBatch(): void
    {
        $db = $this->database();
        $pdo = $db->get();
        $pdo->exec("INSERT INTO settings VALUES ('time_last_change_graph', '100')");
        $pdo->exec("INSERT INTO user_auth_row_cache VALUES (1, 'graph', 'stale', 99)");
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [RefreshingRowCacheStatement::class, [$pdo]]);
        self::assertSame(0, (new CleanInvalidatedRowCache(new InstallationRowCache($db)))());
        self::assertSame(100, (int) $pdo->query('SELECT time FROM user_auth_row_cache')->fetchColumn());
    }

    public function testMalformedCutoffCannotDeleteRows(): void
    {
        $db = $this->database();
        $db->get()->exec("INSERT INTO settings VALUES ('time_last_change_graph', 'not-a-timestamp')");
        $this->expectException(\RuntimeException::class);
        (new CleanInvalidatedRowCache(new InstallationRowCache($db)))();
    }

    public function testMalformedLaterCutoffCannotPartiallyCleanEarlierClasses(): void
    {
        $db = $this->database();
        $pdo = $db->get();
        $pdo->exec("INSERT INTO settings VALUES ('time_last_change_graph', '100'), ('time_last_change_zebra', 'invalid')");
        $pdo->exec("INSERT INTO user_auth_row_cache VALUES (1, 'graph', 'stale', 99)");
        try {
            (new CleanInvalidatedRowCache(new InstallationRowCache($db)))();
            self::fail('Expected invalid cutoff rejection');
        } catch (\RuntimeException) {
            self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM user_auth_row_cache')->fetchColumn());
        }
    }

    public function testInvalidDomainCutoffIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RowCacheInvalidation('graph', -1);
    }

    public function testDisabledHandlerCannotDeleteRows(): void
    {
        $cache = $this->createMock(InvalidatedRowCache::class);
        $cache->expects(self::never())->method('invalidations');
        $this->expectException(\RuntimeException::class);
        (new RowCacheCleanupHandler(new CleanInvalidatedRowCache($cache), null))(new RowCacheCleanup());
    }

    public function testFrameworkRegistersSchedulerTransport(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            self::assertInstanceOf(\Symfony\Component\Scheduler\Messenger\SchedulerTransport::class, $kernel->getContainer()->get('test.service_container')->get('messenger.transport.scheduler_row_cache'));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testSymfonyMessengerDispatchesToApplicationHandler(): void
    {
        $_SERVER['KADUPUL_ROW_CACHE_SCHEDULER'] = '1';
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $cache = $this->createMock(InvalidatedRowCache::class);
            $invalidation = new RowCacheInvalidation('graph', 100);
            $cache->expects(self::once())->method('invalidations')->willReturn([$invalidation]);
            $cache->expects(self::once())->method('remove')->with($invalidation)->willReturn(1);
            $container->set(InvalidatedRowCache::class, $cache);
            $container->get(MessageBusInterface::class)->dispatch(new RowCacheCleanup());
        } finally {
            $kernel->shutdown();
            unset($_SERVER['KADUPUL_ROW_CACHE_SCHEDULER']);
        }
    }
}

/** Simulate a second writer refreshing a candidate after the select finishes. */
final class RefreshingRowCacheStatement extends \PDOStatement
{
    protected function __construct(private readonly \PDO $pdo) {}

    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = parent::fetchAll($mode, ...$args);
        if (str_starts_with($this->queryString, 'SELECT user_id, hash')) {
            $this->pdo->exec("UPDATE user_auth_row_cache SET time = 100 WHERE hash = 'stale'");
        }

        return $rows;
    }
}
