<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ConnectionException;
use Doctrine\DBAL\Driver\PDO\MySQL\Driver as MySqlDriver;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Result;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleOperator;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Application\Command\AnalyzeDatabase;
use Kadupul\Platform\Application\Command\InstallationAccessDenied;
use Kadupul\Platform\Application\Port\DatabaseMaintenance;
use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Infrastructure\Legacy\CollectorIdentity;
use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;
use Kadupul\Platform\Infrastructure\Legacy\LegacyOperatorLog;
use Kadupul\Platform\Infrastructure\Persistence\DbalDatabaseMaintenance;
use Kadupul\Platform\Infrastructure\Symfony\SystemClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

final class AnalyzeDatabaseTest extends TestCase
{
    private string $root;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kadupul-analyze-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/log', 0700, true);
        $this->clock = new MockClock('2026-01-01 00:00:00');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    private function access(bool $allowed): ConsoleOperator&MockObject
    {
        $access = $this->createMock(ConsoleOperator::class);
        $access->method('actor')->willReturn(new Actor(1, 'admin'));
        $access->method('canAdministerInstallation')->willReturn($allowed);

        return $access;
    }

    private function analyze(DatabaseMaintenance $maintenance): AnalyzeDatabase
    {
        return new AnalyzeDatabase($this->access(true), $maintenance, new SystemClock($this->clock));
    }

    /** Every call the use case makes must carry the same target it computed. */
    private function maintenance(bool $collector, DatabaseTarget $expected): DatabaseMaintenance
    {
        $m = $this->createMock(DatabaseMaintenance::class);
        $m->method('isRemoteCollector')->willReturn($collector);
        $m->expects(self::once())->method('tables')->with($expected)->willReturnCallback(function (): array {
            $this->clock->sleep(3);

            return ['host', 'settings'];
        });
        $m->expects(self::once())->method('binlogEnabled')->with($expected)->willReturn(true);
        $m->method('analyze')->willReturnCallback(
            fn(DatabaseTarget $target, string $table, bool $noBinlog): bool => $target === $expected && $table === 'host' && $noBinlog,
        );
        $m->expects(self::once())->method('recordStats')->with($expected, 'ANALYSIS STATS: Analyzing Kadupul Tables Complete.  Total time 3 seconds.');

        return $m;
    }

    private function settings(string $log): Connection
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)');
        $db->executeStatement("INSERT INTO settings VALUES ('path_cactilog', ?)", [$log]);

        return $db;
    }

    private function adapter(Connection $local, ?Connection $main = null): DbalDatabaseMaintenance
    {
        return new DbalDatabaseMaintenance(
            $local,
            $main ?? $local,
            new CollectorIdentity(new InstallationConfiguration($this->root)),
            new LegacyOperatorLog($this->root, new Filesystem()),
        );
    }

    /** A connection with the real MariaDB platform, so identifier quoting is DBAL's own. */
    private function mariaDb(): Connection&MockObject
    {
        return $this->getMockBuilder(Connection::class)
            ->setConstructorArgs([['serverVersion' => '11.8.0-MariaDB'], new MySqlDriver()])
            ->onlyMethods(['executeQuery'])
            ->getMock();
    }

    /** @param list<array<string, string>> $rows */
    private function rows(array $rows): Result
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);

        return $result;
    }

    public function testAnalyzesEveryTableAndLogsStats(): void
    {
        $report = $this->analyze($this->maintenance(false, DatabaseTarget::Local))(false, null);
        self::assertFalse($report->main);
        self::assertTrue($report->noBinlog);
        self::assertSame([['name' => 'host', 'ok' => true], ['name' => 'settings', 'ok' => false]], $report->tables);
        self::assertSame(3, $report->seconds);
    }

    public function testCollectorWithoutLocalFlagUsesTheMainDatabase(): void
    {
        $report = $this->analyze($this->maintenance(true, DatabaseTarget::Main))(false, null);
        self::assertTrue($report->main);
    }

    public function testMissingRealmStopsBeforeAnyTableIsTouched(): void
    {
        $m = $this->createMock(DatabaseMaintenance::class);
        $m->expects(self::never())->method('tables');
        $this->expectException(InstallationAccessDenied::class);
        (new AnalyzeDatabase($this->access(false), $m, new SystemClock($this->clock)))(false, null);
    }

    public function testCollectorWithoutMainConfigurationFails(): void
    {
        // The main connection reports a missing configuration by throwing when
        // it first connects; the use case must let that propagate.
        $m = $this->createMock(DatabaseMaintenance::class);
        $m->method('isRemoteCollector')->willReturn(true);
        $m->method('binlogEnabled')->with(DatabaseTarget::Main)->willThrowException(new \RuntimeException('Main database is not configured.'));
        $this->expectExceptionMessage('Main database is not configured.');
        $this->analyze($m)(false, null);
    }

    public function testCollectorLocalFlagUsesTheLocalDatabase(): void
    {
        $report = $this->analyze($this->maintenance(true, DatabaseTarget::Local))(true, null);
        self::assertFalse($report->main);
    }

    #[DataProvider('operatorDatabases')]
    public function testOperatorIsCheckedOnTheTargetDatabase(bool $collector, bool $local, OperatorDatabase $expected): void
    {
        $operator = $this->access(true);
        $operator->expects(self::once())->method('select')->with('ops', $expected);
        $m = $this->createStub(DatabaseMaintenance::class);
        $m->method('isRemoteCollector')->willReturn($collector);
        $m->method('tables')->willReturn([]);
        (new AnalyzeDatabase($operator, $m, new SystemClock($this->clock)))($local, 'ops');
    }

    /** @return iterable<string, array{bool, bool, OperatorDatabase}> */
    public static function operatorDatabases(): iterable
    {
        yield 'primary' => [false, false, OperatorDatabase::Local];
        yield 'primary with --local' => [false, true, OperatorDatabase::Local];
        yield 'collector' => [true, false, OperatorDatabase::Main];
        yield 'collector with --local' => [true, true, OperatorDatabase::Local];
    }

    public function testEmptyOperatorIsRefusedBeforeAnyLookup(): void
    {
        $operator = $this->createMock(ConsoleOperator::class);
        $operator->expects(self::never())->method(self::anything());
        $m = $this->createMock(DatabaseMaintenance::class);
        $m->expects(self::never())->method(self::anything());
        $this->expectException(InstallationAccessDenied::class);
        (new AnalyzeDatabase($operator, $m, new SystemClock($this->clock)))(false, '');
    }

    public function testTableNamesAreQuotedAsIdentifiers(): void
    {
        $db = $this->mariaDb();
        $db->expects(self::once())->method('executeQuery')->with('ANALYZE TABLE NO_WRITE_TO_BINLOG `we``ird`')->willReturn($this->rows([
            ['Table' => 'we`ird', 'Op' => 'analyze', 'Msg_type' => 'status', 'Msg_text' => 'OK'],
        ]));

        self::assertTrue($this->adapter($db)->analyze(DatabaseTarget::Local, 'we`ird', true));
    }

    public function testAnalyzeReturnsFalseWhenAnyRowReportsAnError(): void
    {
        $db = $this->mariaDb();
        $db->method('executeQuery')->willReturn($this->rows([
            ['Table' => 't', 'Op' => 'analyze', 'Msg_type' => 'status', 'Msg_text' => 'OK'],
            // MariaDB reports the type in mixed case; the check must not depend on it.
            ['Table' => 't', 'Op' => 'analyze', 'Msg_type' => 'Error', 'Msg_text' => 'boom'],
        ]));

        self::assertFalse($this->adapter($db)->analyze(DatabaseTarget::Local, 't', false));
    }

    public function testAnalyzeReturnsFalseWhenTheQueryThrows(): void
    {
        $db = $this->mariaDb();
        $db->method('executeQuery')->willThrowException(new ConnectionException('gone'));

        self::assertFalse($this->adapter($db)->analyze(DatabaseTarget::Local, 't', false));
    }

    public function testEachTargetUsesItsOwnConnection(): void
    {
        $local = $this->mariaDb();
        $local->expects(self::never())->method('executeQuery');
        $main = $this->mariaDb();
        $main->expects(self::once())->method('executeQuery')->willReturn($this->rows([]));

        self::assertTrue($this->adapter($local, $main)->analyze(DatabaseTarget::Main, 't', false));
    }

    public function testRecordStatsLogsThroughTheSelectedConnection(): void
    {
        $main = $this->settings($this->root . '/log/main.log');
        $local = $this->settings($this->root . '/log/local.log');

        $this->adapter($local, $main)->recordStats(DatabaseTarget::Main, 'message');

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} - SYSTEM message\n$/', (string) file_get_contents($this->root . '/log/main.log'));
        self::assertFileDoesNotExist($this->root . '/log/local.log');
    }

    public function testAnalyzeAgainstARealMariaDbConnection(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('Set KADUPUL_TEST_MYSQL_DSN to run against a real MariaDB.');
        }
        // The variable holds a PDO DSN, shared with tests/security; its keys
        // happen to match DBAL's parameter names.
        $params = ['driver' => 'pdo_mysql', 'user' => getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', 'password' => getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: ''];
        foreach (explode(';', (string) preg_replace('/^mysql:/', '', $dsn)) as $pair) {
            [$key, $value] = explode('=', $pair, 2) + [1 => ''];
            if (in_array($key, ['host', 'port', 'dbname', 'unix_socket', 'charset'], true)) {
                $params[$key] = $key === 'port' ? (int) $value : $value;
            }
        }
        $db = DriverManager::getConnection($params);

        $tables = ['kadupul_analyze_a', 'kadupul_analyze_b', 'kadupul_analyze_c'];
        foreach ($tables as $table) {
            $db->executeStatement('DROP TABLE IF EXISTS `' . $table . '`');
            $db->executeStatement('CREATE TABLE `' . $table . '` (id INT PRIMARY KEY)');
        }

        $maintenance = $this->adapter($db);
        self::assertSame([], array_diff($tables, $maintenance->tables(DatabaseTarget::Local)));
        $maintenance->binlogEnabled(DatabaseTarget::Local);
        foreach ($tables as $table) {
            self::assertTrue($maintenance->analyze(DatabaseTarget::Local, $table, false));
        }

        // An unconsumed ANALYZE TABLE result set would make the server refuse
        // the next statement; confirm a normal query still runs afterward.
        self::assertSame('1', (string) $db->fetchOne('SELECT 1'));

        foreach ($tables as $table) {
            $db->executeStatement('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }
}
