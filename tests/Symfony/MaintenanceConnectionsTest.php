<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Infrastructure\Legacy\LegacyOperatorLog;
use Kadupul\Platform\Infrastructure\Persistence\MaintenanceConnections;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

final class MaintenanceConnectionsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kadupul-maintenance-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/log', 0700, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    /** An installation database whose settings send cacti.log into the test directory, at the given verbosity. */
    private function connections(?string $verbosity): MaintenanceConnections
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)');
        $db->executeStatement("INSERT INTO settings VALUES ('path_cactilog', ?)", [$this->root . '/log/cacti.log']);
        if ($verbosity !== null) {
            $db->executeStatement("INSERT INTO settings VALUES ('log_verbosity', ?)", [$verbosity]);
        }

        return new MaintenanceConnections($db, $db, new LegacyOperatorLog($this->root, new Filesystem(), new MockClock()));
    }

    private function log(): string
    {
        return is_file($this->root . '/log/cacti.log') ? (string) file_get_contents($this->root . '/log/cacti.log') : '';
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function verbosities(): iterable
    {
        // Below DEBUG, at DEBUG, and DEVDBG, whose selective gate keeps a plain
        // debug-level message out unless the caller tags it POLLER_VERBOSITY_DEVDBG.
        yield 'no setting' => [null, false];
        yield 'HIGH' => ['4', false];
        yield 'DEBUG' => ['5', true];
        yield 'DEVDBG' => ['6', false];
    }

    #[DataProvider('verbosities')]
    public function testTheSqlLineIsWrittenOnlyAtDebugVerbosity(?string $verbosity, bool $expectSqlLine): void
    {
        $connections = $this->connections($verbosity);
        self::assertTrue($connections->execute(DatabaseTarget::Local, 'CREATE TABLE t (id INTEGER)'));
        self::assertFalse($connections->execute(DatabaseTarget::Local, 'ALTER TABLE missing ADD COLUMN x INTEGER'));
        $log = $this->log();
        self::assertSame($expectSqlLine, str_contains($log, "SQL: 'ALTER TABLE missing ADD COLUMN x INTEGER'"));
        // errorInfo()[2] carries the driver's own message; PDO's exception adds a
        // SQLSTATE[...] prefix that db_execute() never logged.
        self::assertStringContainsString('DBCALL ERROR: A DB Exec Failed!, Error: no such table: missing', $log);
        self::assertStringNotContainsString('SQLSTATE', $log);
    }
}
