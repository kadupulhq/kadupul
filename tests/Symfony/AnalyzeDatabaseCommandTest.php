<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Platform\Application\Command\AnalyzeDatabase;
use Kadupul\Platform\Application\Port\DatabaseMaintenance;
use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Infrastructure\Doctrine\InstallationConnectionMiddleware;
use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\AnalyzeDatabaseCommand;
use Kadupul\Platform\Infrastructure\Symfony\Console\AnalyzeDatabaseLegacyArguments;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Kadupul\Platform\Infrastructure\Symfony\SystemClock;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class AnalyzeDatabaseCommandTest extends TestCase
{
    private string $root;
    private Connection $db;
    private CliConsoleAccess $access;
    private CliPresentation $presentation;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kadupul-analyze-command-' . bin2hex(random_bytes(8));
        (new Filesystem())->dumpFile($this->root . '/include/cacti_version', "1.3.0\n");
        $this->db = $this->installation();
        $this->access = new CliConsoleAccess($this->db, $this->db);
        $this->presentation = new CliPresentation();
    }

    /** One installation database with user 1, admin, holding realms 8 and 15. */
    private function installation(): Connection
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE version (cacti TEXT)',
            "INSERT INTO version VALUES ('1.3.0')",
            'CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)',
            "INSERT INTO settings VALUES ('admin_user', '1')",
            'CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT, enabled TEXT, locked TEXT, must_change_password TEXT)',
            "INSERT INTO user_auth VALUES (1, 'admin', 'on', '', '')",
            'CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER)',
            'INSERT INTO user_auth_realm VALUES (1, 8), (1, 15)',
            'CREATE TABLE user_auth_group (id INTEGER, enabled TEXT)',
            'CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)',
            'CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)',
        ] as $statement) {
            $db->executeStatement($statement);
        }

        return $db;
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    /** A remote collector whose main database has "host" and "settings"; only "host" analyzes cleanly. */
    private function maintenance(): DatabaseMaintenance&MockObject
    {
        $maintenance = $this->createMock(DatabaseMaintenance::class);
        $maintenance->method('isRemoteCollector')->willReturn(true);
        $maintenance->method('binlogEnabled')->willReturn(false);
        $maintenance->method('tables')->willReturn(['host', 'settings']);
        $maintenance->method('analyze')->willReturnCallback(fn(DatabaseTarget $target, string $table): bool => $table === 'host');

        return $maintenance;
    }

    private function tester(DatabaseMaintenance $maintenance): CommandTester
    {
        $analyze = new AnalyzeDatabase($this->access, $maintenance, new SystemClock(new MockClock()));
        $version = new InstallationVersion($this->root, $this->db, new Filesystem());
        $command = new AnalyzeDatabaseCommand($analyze, $version, $this->presentation, new ResultRenderer());

        return new CommandTester(new Command(null, $command));
    }

    public function testLegacyOutputMatchesTheOriginalScript(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester($this->maintenance());
        self::assertSame(0, $tester->execute([]));
        self::assertSame("NOTE: Analyzing All Kadupul Database Tables\nNOTE: Repairing Tables for Main Database\nNOTE: Analyzing Table -> 'host' Successful\nNOTE: Analyzing Table -> 'settings' Failed\n", $tester->getDisplay());
    }

    public function testJsonOutput(): void
    {
        $tester = $this->tester($this->maintenance());
        self::assertSame(0, $tester->execute(['--json' => true]));
        self::assertSame(['status' => 'ok', 'database' => 'main', 'binlog_enabled' => false, 'tables' => [['name' => 'host', 'ok' => true], ['name' => 'settings', 'ok' => false]]], json_decode($tester->getDisplay(), true));
    }

    public function testLocalOptionReachesTheUseCase(): void
    {
        $tester = $this->tester($this->maintenance());
        self::assertSame(0, $tester->execute(['--json' => true, '--local' => true]));
        self::assertSame('local', json_decode($tester->getDisplay(), true)['database']);
    }

    public function testAccessDeniedFailsWithoutNamingTheAccount(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $maintenance = $this->createMock(DatabaseMaintenance::class);
        $maintenance->expects(self::never())->method('tables');
        $tester = $this->tester($maintenance);
        self::assertSame(1, $tester->execute(['--as' => 'someone']));
        self::assertSame("ERROR: Unknown or unauthorized operator\n", $tester->getDisplay());
    }

    public function testLegacyVersionLine(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Version);
        $tester = $this->tester($this->maintenance());
        self::assertSame(0, $tester->execute([]));
        self::assertMatchesRegularExpression('/^Kadupul Analyze Database Utility, Version 1\.3\.0 \(DB: 1\.3\.0\), Copyright \(C\) 2004-\d{4} The Cacti Group\n$/', $tester->getDisplay());
    }

    public function testMissingVersionFileFailsOnlyTheVersionLine(): void
    {
        // The original died with "ERROR: failed to find cacti version file" and
        // exit 0 on every path; the command reads the file only for this line.
        (new Filesystem())->remove($this->root . '/include/cacti_version');
        $this->presentation->forLegacy(LegacyRequest::Version);
        $tester = $this->tester($this->maintenance());
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame("ERROR: Database analysis failed\n", $tester->getDisplay());
        $this->presentation->forLegacy(LegacyRequest::Run);
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringStartsWith("NOTE: Analyzing All Kadupul Database Tables\n", $tester->getDisplay());
    }

    public function testLegacyHelpMatchesTheOriginalScript(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Help);
        $tester = $this->tester($this->maintenance());
        self::assertSame(0, $tester->execute([]));
        self::assertSame(
            'Kadupul Analyze Database Utility, Version 1.3.0 (DB: 1.3.0), Copyright (C) 2004-' . date('Y') . " The Cacti Group\n"
            . "\nusage: analyze_database.php [-d|--debug]\n\n"
            . "A utility to recalculate the cardinality of indexes within the Kadupul database.\n"
            . "It's important to periodically run this utility especially on larger systems.\n\n"
            . "Optional:\n"
            . "     --local   - Perform the action on the Remote Data Collector if run from there\n"
            . "-d | --debug   - Display verbose output during execution\n\n",
            $tester->getDisplay(),
        );
    }

    public function testEveryOriginalFlagIsMapped(): void
    {
        $map = new AnalyzeDatabaseLegacyArguments();
        foreach (['-d', '--debug', '--local'] as $flag) {
            self::assertNull($map->translate([$flag])[1]);
        }
        foreach (['--version', '-V', '-v'] as $flag) {
            self::assertSame(LegacyRequest::Version, $map->translate([$flag])[1]);
        }
        foreach (['--help', '-H', '-h'] as $flag) {
            self::assertSame(LegacyRequest::Help, $map->translate([$flag])[1]);
        }
        self::assertSame([['--as' => 'ops'], null], $map->translate(['--as=ops']));
        self::assertSame(['ERROR: Invalid Parameter --x', ''], $map->invalid('--x'));
    }

    public function testHumanOutputUsesSymfonyStyle(): void
    {
        $maintenance = $this->createMock(DatabaseMaintenance::class);
        $maintenance->method('tables')->willReturn(['host', 'settings']);
        $maintenance->method('analyze')->willReturn(true);
        $tester = $this->tester($maintenance);
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('[OK] Analyzed 2 tables.', $tester->getDisplay());
    }

    public function testHumanOutputWarnsWhenATableFails(): void
    {
        $tester = $this->tester($this->maintenance());
        self::assertSame(0, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('settings: failed', $display);
        self::assertStringContainsString('[WARNING] Analyzed 2 tables; 1 failed.', $display);
    }

    public function testHumanFailureGoesToStderr(): void
    {
        $tester = $this->tester($this->createStub(DatabaseMaintenance::class));
        self::assertSame(Command::FAILURE, $tester->execute(['--as' => 'someone'], ['capture_stderr_separately' => true]));
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('[ERROR] Unknown or unauthorized operator.', $tester->getErrorOutput());
    }

    public function testEmptyOperatorIsInvalid(): void
    {
        $maintenance = $this->createMock(DatabaseMaintenance::class);
        $maintenance->expects(self::never())->method(self::anything());
        $tester = $this->tester($maintenance);
        self::assertSame(Command::INVALID, $tester->execute(['--as' => '']));
        // select() was never reached, so no operator lookup could have run.
        $this->expectException(\LogicException::class);
        $this->access->actor();
    }

    public function testCollectorLocalRunNeedsNoMainDatabase(): void
    {
        // The real connection middleware, on a collector whose config.php has
        // no rdatabase_* settings: any use of main throws on connect.
        (new Filesystem())->dumpFile($this->root . '/include/config.php', "<?php\n\$database_type = 'mysql';\n\$poller_id = 3;\n");
        $middleware = new InstallationConnectionMiddleware(fn(): InstallationConfiguration => new InstallationConfiguration($this->root));
        $main = DriverManager::getConnection(['driver' => 'pdo_mysql', 'driverOptions' => ['kadupul_target' => 'main']], (new Configuration())->setMiddlewares([$middleware]));
        $this->access = new CliConsoleAccess($this->db, $main);
        $maintenance = $this->maintenance();
        $maintenance->expects(self::once())->method('tables')->with(DatabaseTarget::Local);
        $tester = $this->tester($maintenance);
        self::assertSame(0, $tester->execute(['--json' => true, '--local' => true]));
        self::assertSame('local', json_decode($tester->getDisplay(), true)['database']);
        $this->expectExceptionMessage('Main database is not configured.');
        $main->fetchOne('SELECT 1');
    }

    public function testCollectorRunChecksTheOperatorOnMain(): void
    {
        // The local copy disables admin, so only a lookup on main succeeds.
        $this->db->executeStatement("UPDATE user_auth SET enabled = '' WHERE id = 1");
        $this->access = new CliConsoleAccess($this->db, $this->installation());
        $tester = $this->tester($this->maintenance());
        self::assertSame(0, $tester->execute(['--json' => true]));
        self::assertSame('main', json_decode($tester->getDisplay(), true)['database']);
        self::assertSame(1, $tester->execute(['--json' => true, '--local' => true]));
        self::assertSame(['status' => 'denied'], json_decode($tester->getDisplay(), true));
    }

    public function testMissingMainDatabaseIsNamed(): void
    {
        $maintenance = $this->createMock(DatabaseMaintenance::class);
        $maintenance->method('isRemoteCollector')->willReturn(true);
        $maintenance->method('binlogEnabled')->willThrowException(new \RuntimeException('Main database is not configured.'));
        $tester = $this->tester($maintenance);
        self::assertSame(1, $tester->execute(['--json' => true]));
        self::assertSame(['status' => 'failed', 'error' => 'Main database is not configured'], json_decode($tester->getDisplay(), true));
    }

    public function testUnexpectedFailureHidesItsMessage(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $maintenance = $this->createMock(DatabaseMaintenance::class);
        $maintenance->method('tables')->willThrowException(new \Error("SQLSTATE[28000] Access denied for user 'cacti'@'db.internal'"));
        $tester = $this->tester($maintenance);
        self::assertSame(1, $tester->execute([]));
        self::assertSame("ERROR: Database analysis failed\n", $tester->getDisplay());
    }

    public function testHelpListsNoHiddenShimOptions(): void
    {
        $process = new Process([PHP_BINARY, 'bin/console', 'help', 'kadupul:database:analyze'], dirname(__DIR__, 2), ['APP_ENV' => 'test', 'APP_DEBUG' => '1']);
        $process->mustRun();
        $help = $process->getOutput();
        foreach (['--local', '--debug', '--as=AS', '--json', 'Recalculate index cardinality'] as $expected) {
            self::assertStringContainsString($expected, $help);
        }
        self::assertStringNotContainsString('output-mode', $help);
        self::assertStringNotContainsString('legacy-', $help);
    }
}
