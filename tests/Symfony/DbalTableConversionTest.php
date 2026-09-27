<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Domain\Schema\TableChange;
use Kadupul\Platform\Domain\Schema\TableCharset;
use Kadupul\Platform\Domain\Schema\TableStatus;
use Kadupul\Platform\Infrastructure\Legacy\LegacyOperatorLog;
use Kadupul\Platform\Infrastructure\Persistence\DbalTableConversion;
use Kadupul\Platform\Infrastructure\Persistence\MaintenanceConnections;
use Kadupul\Tests\Fixtures\RealMariaDb;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

final class DbalTableConversionTest extends TestCase
{
    use RealMariaDb;

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kadupul-convert-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/log', 0700, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    /** An installation database whose settings send cacti.log into the test directory. */
    private function sqlite(): Connection
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)');
        $db->executeStatement("INSERT INTO settings VALUES ('path_cactilog', ?)", [$this->root . '/log/cacti.log']);

        return $db;
    }

    private function connections(Connection $local, ?Connection $main = null): MaintenanceConnections
    {
        return new MaintenanceConnections($local, $main ?? $local, new LegacyOperatorLog($this->root, new Filesystem(), new MockClock()));
    }

    private function adapter(Connection $db): DbalTableConversion
    {
        return new DbalTableConversion($this->root, new Filesystem(), $this->connections($db));
    }

    private function log(): string
    {
        return is_file($this->root . '/log/cacti.log') ? (string) file_get_contents($this->root . '/log/cacti.log') : '';
    }

    public function testBaseTablesAreReadFromCactiSqlAsTheInstallerReadsThem(): void
    {
        (new Filesystem())->dumpFile($this->root . '/cacti.sql', "--\n-- Table structure for table `host`\n--\n\nCREATE TABLE `host` (\n  id int\n);\nCREATE TABLE settings (\r\n  name varchar(50)\n);\n");
        self::assertSame(['host', 'settings'], $this->adapter($this->sqlite())->baseTables());
    }

    public function testAMissingCactiSqlMeansNoBaseTables(): void
    {
        self::assertSame([], $this->adapter($this->sqlite())->baseTables());
    }

    public function testStatementsQuoteTheTableAndUseOnlyAllowListedClauses(): void
    {
        // A MariaDB platform without a server: quoting needs no connection.
        $db = DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '11.8.0-MariaDB']);
        $adapter = $this->adapter($db);
        self::assertSame(
            'ALTER TABLE `we``ird` ROW_FORMAT=Dynamic, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, ENGINE=InnoDB',
            $adapter->statement(DatabaseTarget::Local, 'we`ird', new TableChange(true, TableCharset::Utf8mb4, true)),
        );
        self::assertSame('ALTER TABLE `host`', $adapter->statement(DatabaseTarget::Main, 'host', new TableChange(false, null, false)));
    }

    public function testAHostileNameStaysOneQuotedIdentifier(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '11.8.0-MariaDB']);
        self::assertSame(
            'ALTER TABLE `a``; DROP TABLE host; -- ` ENGINE=InnoDB',
            $this->adapter($db)->statement(DatabaseTarget::Local, 'a`; DROP TABLE host; -- ', new TableChange(false, null, true)),
        );
    }

    public function testAFailedStatementIsLoggedLikeDbExecute(): void
    {
        $db = $this->sqlite();
        // db_execute() writes its SQL line only at debug verbosity (lib/database.php:638).
        $db->executeStatement("INSERT INTO settings VALUES ('log_verbosity', '5')");
        $connections = $this->connections($db);
        self::assertTrue($connections->execute(DatabaseTarget::Local, 'CREATE TABLE t (id INTEGER)'));
        self::assertFalse($connections->execute(DatabaseTarget::Local, 'ALTER TABLE missing ADD COLUMN x INTEGER'));
        $log = $this->log();
        self::assertMatchesRegularExpression("/ - DBCALL ERROR: A DB Exec Failed!, Error: \\d+, SQL: 'ALTER TABLE missing ADD COLUMN x INTEGER'\n/", $log);
        self::assertMatchesRegularExpression('/ - DBCALL ERROR: A DB Exec Failed!, Error: .*no such table: missing\n/', $log);
    }

    public function testEachTargetUsesItsOwnConnection(): void
    {
        $local = $this->sqlite();
        $main = $this->sqlite();
        $connections = $this->connections($local, $main);
        self::assertTrue($connections->execute(DatabaseTarget::Main, 'CREATE TABLE only_main (id INTEGER)'));
        $names = $main->createSchemaManager()->listTableNames();
        sort($names);
        self::assertSame(['only_main', 'settings'], $names);
        self::assertSame(['settings'], $local->createSchemaManager()->listTableNames());
    }

    public function testRecordFailureWritesTheOriginalConvertLine(): void
    {
        $this->adapter($this->sqlite())->recordFailure(DatabaseTarget::Local, "FATAL: Conversion of Table 'a' Failed.  Command: 'ALTER TABLE `a`  ENGINE=Innodb'");
        self::assertMatchesRegularExpression("/^\\d{2}\\/\\d{2}\\/\\d{4} \\d{2}:\\d{2}:\\d{2} - CONVERT FATAL: Conversion of Table 'a' Failed\\.  Command: 'ALTER TABLE `a`  ENGINE=Innodb'\n$/", $this->log());
    }

    public function testLoggingNeverFailsTheCommand(): void
    {
        // No settings table: LegacyOperatorLog cannot read its destination.
        $bare = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connections = $this->connections($bare);
        $connections->log(DatabaseTarget::Local, 'CONVERT', 'FATAL: x');
        self::assertFalse($connections->execute(DatabaseTarget::Local, 'ALTER TABLE missing ADD COLUMN x INTEGER'));
        self::assertSame('', $this->log());
    }

    public function testAgainstARealMariaDb(): void
    {
        $db = $this->realMariaDb();
        try {
            $db->executeStatement('DROP TABLE IF EXISTS kadupul_convert_probe');
            $db->executeStatement("CREATE TABLE kadupul_convert_probe (id INT UNSIGNED NOT NULL PRIMARY KEY, name VARCHAR(20) NOT NULL DEFAULT '') ENGINE=MyISAM DEFAULT CHARSET=latin1");
            $adapter = $this->adapter($db);
            // Read from the connection's own DATABASE(), whatever config.php calls it.
            $before = $adapter->tableStatuses(DatabaseTarget::Local)->status('kadupul_convert_probe');
            self::assertSame(['MyISAM', 'latin1_swedish_ci', 0], [$before?->engine, $before?->collation, $before?->rows]);
            self::assertTrue($adapter->innodbEnabled(DatabaseTarget::Local));
            self::assertIsBool($adapter->filePerTable(DatabaseTarget::Local));
            self::assertTrue($adapter->convert(DatabaseTarget::Local, 'kadupul_convert_probe', new TableChange(true, TableCharset::Utf8mb4, true)));
            self::assertEquals(new TableStatus('InnoDB', 'utf8mb4_unicode_ci', 'Dynamic', 0), $adapter->tableStatuses(DatabaseTarget::Local)->status('kadupul_convert_probe'));
            // A bare ALTER TABLE is valid and changes nothing, which --rebuild relies on.
            self::assertTrue($adapter->convert(DatabaseTarget::Local, 'kadupul_convert_probe', new TableChange(false, null, false)));
        } finally {
            $db->executeStatement('DROP TABLE IF EXISTS kadupul_convert_probe');
        }
    }

    public function testAHostileListedNameConvertsOnlyItselfOnARealMariaDb(): void
    {
        $db = $this->realMariaDb();
        // MariaDB rejects a trailing space in a table name, so the comment ends in x.
        $hostile = 'kadupul`; DROP TABLE kadupul_convert_bystander; -- x';
        $quoted = $db->quoteSingleIdentifier($hostile);
        try {
            $db->executeStatement('DROP TABLE IF EXISTS kadupul_convert_bystander, ' . $quoted);
            $db->executeStatement('CREATE TABLE kadupul_convert_bystander (id INT) ENGINE=MyISAM');
            $db->executeStatement('CREATE TABLE ' . $quoted . ' (id INT) ENGINE=MyISAM');
            $adapter = $this->adapter($db);
            // The name comes back from information_schema exactly as created.
            self::assertTrue($adapter->tableStatuses(DatabaseTarget::Local)->has($hostile));
            self::assertTrue($adapter->convert(DatabaseTarget::Local, $hostile, new TableChange(false, null, true)));
            $catalog = $adapter->tableStatuses(DatabaseTarget::Local);
            self::assertSame('InnoDB', $catalog->status($hostile)?->engine);
            self::assertSame('MyISAM', $catalog->status('kadupul_convert_bystander')?->engine);
        } finally {
            $db->executeStatement('DROP TABLE IF EXISTS kadupul_convert_bystander');
            $db->executeStatement('DROP TABLE IF EXISTS ' . $quoted);
        }
    }

    public function testConvertSendsNothingForATableTheCatalogDoesNotList(): void
    {
        $db = $this->realMariaDb();
        // A temporary table accepts ALTER TABLE but is not a BASE TABLE, so it
        // shows whether convert() checks the catalog before sending.
        try {
            $db->executeStatement('CREATE TEMPORARY TABLE kadupul_convert_temp (id INT) ENGINE=MyISAM');
            $adapter = $this->adapter($db);
            self::assertFalse($adapter->tableStatuses(DatabaseTarget::Local)->has('kadupul_convert_temp'));
            self::assertFalse($adapter->convert(DatabaseTarget::Local, 'kadupul_convert_temp', new TableChange(false, null, true)));
            self::assertStringContainsString('ENGINE=MyISAM', (string) $db->fetchAssociative('SHOW CREATE TABLE kadupul_convert_temp')['Create Table']);
        } finally {
            $db->executeStatement('DROP TEMPORARY TABLE IF EXISTS kadupul_convert_temp');
        }
    }
}
