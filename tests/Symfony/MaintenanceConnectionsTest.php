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
use Kadupul\Tests\Fixtures\RealMariaDb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

final class MaintenanceConnectionsTest extends TestCase
{
    use RealMariaDb;

    private string $root;
    private Connection $db;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kadupul-maintenance-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/log', 0700, true);
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)');
        $this->db->executeStatement("INSERT INTO settings VALUES ('path_cactilog', ?)", [$this->root . '/log/cacti.log']);
        $this->db->executeStatement('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    /** Connections over $db, by default the setUp() database, whose settings send cacti.log into the test directory. */
    private function connections(?Connection $db = null): MaintenanceConnections
    {
        $db ??= $this->db;

        return new MaintenanceConnections($db, $db, new LegacyOperatorLog($this->root, new Filesystem(), new MockClock()));
    }

    /** @return list<string> the log lines without their date */
    private function log(): array
    {
        $log = is_file($this->root . '/log/cacti.log') ? (string) file_get_contents($this->root . '/log/cacti.log') : '';

        return array_values(array_map(static fn(string $line): string => explode(' - ', $line, 2)[1], array_filter(explode("\n", $log))));
    }

    /** @return iterable<string, array{?string, list<string>}> */
    public static function verbosities(): iterable
    {
        // errorInfo()[2] carries the driver's own message; PDO's exception adds a
        // SQLSTATE[...] prefix that db_execute() never logged.
        $error = 'DBCALL ERROR: A DB Exec Failed!, Error: no such table: missing';
        $sql = "DBCALL ERROR: A DB Exec Failed!, Error: 1, SQL: 'ALTER TABLE missing ADD COLUMN x INTEGER'";
        yield 'no setting, which reads as LOW' => [null, [$error]];
        yield 'HIGH' => ['4', [$error]];
        yield 'DEBUG' => ['5', [$sql, $error]];
        // DEVDBG passes only its own level and LOW and below (lib/functions.php:1347-1352),
        // so a plain debug-level message stays out.
        yield 'DEVDBG' => ['6', [$error]];
    }

    /** @param list<string> $lines */
    #[DataProvider('verbosities')]
    public function testTheSqlLineIsWrittenOnlyAtDebugVerbosity(?string $verbosity, array $lines): void
    {
        if ($verbosity !== null) {
            $this->db->executeStatement("INSERT INTO settings VALUES ('log_verbosity', ?)", [$verbosity]);
        }
        $connections = $this->connections();

        self::assertTrue($connections->execute(DatabaseTarget::Local, 'CREATE TABLE u (id INTEGER)'));
        self::assertFalse($connections->execute(DatabaseTarget::Local, 'ALTER TABLE missing ADD COLUMN x INTEGER'));
        self::assertSame($lines, $this->log());
    }

    public function testAFixRunsInOneTransactionAndRollsBackWhole(): void
    {
        $connections = $this->connections();

        self::assertSame(2, $connections->write(DatabaseTarget::Local, [['INSERT INTO t VALUES (?, ?)', [1, 'a']], ['INSERT INTO t VALUES (?, ?)', [2, null]]]));
        self::assertNull($connections->write(DatabaseTarget::Local, [['INSERT INTO t VALUES (?, ?)', [3, 'c']], ['INSERT INTO t VALUES (?, ?)', [1, 'dup']]]));

        self::assertSame([1, 2], array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM t ORDER BY id')));
        self::assertSame(['DBCALL ERROR: A DB Exec Failed!, Error: UNIQUE constraint failed: t.id'], $this->log());
    }

    public function testARefusedFixLogsTheStatementThatFailedAtDebugVerbosity(): void
    {
        $this->db->executeStatement("INSERT INTO settings VALUES ('log_verbosity', '5')");

        self::assertNull($this->connections()->write(DatabaseTarget::Local, [['INSERT INTO t VALUES (?, ?)', [1, 'a']], ['UPDATE missing SET v = ?', ['x']]]));

        self::assertSame([], $this->db->fetchFirstColumn('SELECT id FROM t'));
        self::assertSame([
            "DBCALL ERROR: A DB Exec Failed!, Error: 1, SQL: 'UPDATE missing SET v = ?'",
            'DBCALL ERROR: A DB Exec Failed!, Error: no such table: missing',
        ], $this->log());
    }

    public function testAFailedBeginLogsNoSqlLine(): void
    {
        $this->db->executeStatement("INSERT INTO settings VALUES ('log_verbosity', '5')");
        // A transaction opened behind DBAL's back makes PDO refuse BEGIN.
        $this->db->executeStatement('BEGIN');

        self::assertNull($this->connections()->write(DatabaseTarget::Local, [['INSERT INTO t VALUES (?, ?)', [1, 'a']]]));

        $this->db->executeStatement('ROLLBACK');
        self::assertSame([], $this->db->fetchFirstColumn('SELECT id FROM t'));
        self::assertSame(['DBCALL ERROR: A DB Exec Failed!, Error: There is already an active transaction'], $this->log());
    }

    public function testTheCatalogListsBaseTablesInBinaryOrderOnARealMariaDb(): void
    {
        $db = $this->realMariaDb();
        try {
            $db->executeStatement('DROP VIEW IF EXISTS kadupul_view');
            $db->executeStatement('DROP TABLE IF EXISTS kadupul_b, kadupul_B, kadupul_a_x');
            $db->executeStatement('CREATE TABLE kadupul_b (id int) ENGINE=MyISAM');
            $db->executeStatement('CREATE TABLE kadupul_B (id int) ENGINE=InnoDB');
            $db->executeStatement('CREATE TABLE kadupul_a_x (id int) ENGINE=InnoDB');
            $db->executeStatement('CREATE VIEW kadupul_view AS SELECT 1 AS one');

            $names = array_values(array_filter($this->connections($db)->tableCatalog(DatabaseTarget::Local)->names(), static fn(string $name): bool => str_starts_with($name, 'kadupul_')));

            self::assertSame(['kadupul_B', 'kadupul_a_x', 'kadupul_b'], $names);
            self::assertSame('MyISAM', $this->connections($db)->tableCatalog(DatabaseTarget::Local)->status('kadupul_b')?->engine);
        } finally {
            $db->executeStatement('DROP VIEW IF EXISTS kadupul_view');
            $db->executeStatement('DROP TABLE IF EXISTS kadupul_b, kadupul_B, kadupul_a_x');
        }
    }

}
