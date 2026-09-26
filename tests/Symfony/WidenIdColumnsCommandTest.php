<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Platform\Application\Command\MaintenanceTarget;
use Kadupul\Platform\Application\Command\SchemaChangeAudit;
use Kadupul\Platform\Application\Command\WidenIdColumns;
use Kadupul\Platform\Application\Port\ColumnCatalog;
use Kadupul\Platform\Application\Port\ColumnWidening;
use Kadupul\Platform\Application\Port\DatabaseMaintenance;
use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Application\ReadModel\WideningEvent;
use Kadupul\Platform\Application\ReadModel\WideningReport;
use Kadupul\Platform\Domain\Schema\ColumnDefinition;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Kadupul\Platform\Infrastructure\Symfony\Console\WidenIdColumnsCommand;
use Kadupul\Platform\Infrastructure\Symfony\Console\WidenIdColumnsLegacyArguments;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class WidenIdColumnsCommandTest extends TestCase
{
    private const string HEADER = "NOTE: Fixing MediumInt Columns for Local Database\n";

    private string $root;
    private Connection $db;
    private CliPresentation $presentation;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kadupul-widen-command-' . bin2hex(random_bytes(8));
        (new Filesystem())->dumpFile($this->root . '/include/cacti_version', "1.3.0\n");
        // User 1, admin, holds Console Access (8) and Installation/Upgrades (26).
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE version (cacti TEXT)',
            "INSERT INTO version VALUES ('1.3.0')",
            'CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)',
            "INSERT INTO settings VALUES ('admin_user', '1')",
            'CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT, enabled TEXT, locked TEXT, must_change_password TEXT)',
            "INSERT INTO user_auth VALUES (1, 'admin', 'on', '', '')",
            'CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER)',
            'INSERT INTO user_auth_realm VALUES (1, 8), (1, 26)',
            'CREATE TABLE user_auth_group (id INTEGER, enabled TEXT)',
            'CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)',
            'CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)',
        ] as $statement) {
            $this->db->executeStatement($statement);
        }
        $this->presentation = new CliPresentation();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    /** The fresh schema plus table a, whose graph_id is still mediumint. */
    private function tester(bool $ok = true, ?\Throwable $failure = null): CommandTester
    {
        $schema = WidenIdColumnsTest::freshSchema();
        $schema['a'] = [new ColumnDefinition('graph_id', 'mediumint(8) unsigned', false, '0', '')];
        $widening = $this->createStub(ColumnWidening::class);
        if ($failure === null) {
            $widening->method('catalog')->willReturn(new ColumnCatalog($schema));
        } else {
            $widening->method('catalog')->willThrowException($failure);
        }
        $widening->method('statement')->willReturnCallback(static fn(DatabaseTarget $target, string $table): string => 'ALTER TABLE `' . $table . '` x');
        $widening->method('widen')->willReturn($ok);
        $maintenance = $this->createStub(DatabaseMaintenance::class);
        $widen = new WidenIdColumns(new MaintenanceTarget(new CliConsoleAccess($this->db, $this->db), $maintenance), $widening, new SchemaChangeAudit($this->createStub(AuditTrail::class)));
        // A year that is not the current one proves the version line reads the clock.
        $command = new WidenIdColumnsCommand($widen, new InstallationVersion($this->root, $this->db, new Filesystem()), $this->presentation, new ResultRenderer(), new MockClock('2031-06-01 00:00:00'));

        return new CommandTester(new Command(null, $command));
    }

    private function versionLine(): string
    {
        return 'Kadupul Fix Database Range Issue, Version 1.3.0 (DB: 1.3.0), Copyright (C) 2004-2031 The Cacti Group';
    }

    public function testLegacyOutputMatchesTheOriginal(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester();
        self::assertSame(0, $tester->execute([]));
        self::assertSame(self::HEADER . "NOTE: Column widths adjusted on 1 Tables!\n", $tester->getDisplay());
    }

    public function testLegacyDebugLinesFollowTheOriginalOrder(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester(false);
        // fix_mediumint.php exited 0 whatever its statements did.
        self::assertSame(0, $tester->execute(['--debug' => true]));
        $display = $tester->getDisplay();
        self::assertStringStartsWith(self::HEADER
            . "DEBUG: Column data_template_data_id in Table data_input_data already converted.\n"
            . "DEBUG: Column id in Table data_template_data already converted.\n", $display);
        self::assertStringEndsWith("DEBUG: Updating Table a.\nNOTE: Column widths adjusted on 1 Tables!\n", $display);
    }

    public function testLegacyDebugReportsAMissingNamedColumn(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $widening = $this->createStub(ColumnWidening::class);
        $widening->method('catalog')->willReturn(new ColumnCatalog([]));
        $widen = new WidenIdColumns(new MaintenanceTarget(new CliConsoleAccess($this->db, $this->db), $this->createStub(DatabaseMaintenance::class)), $widening, new SchemaChangeAudit($this->createStub(AuditTrail::class)));
        $tester = new CommandTester(new Command(null, new WidenIdColumnsCommand($widen, new InstallationVersion($this->root, $this->db, new Filesystem()), $this->presentation, new ResultRenderer(), new MockClock())));
        self::assertSame(0, $tester->execute(['--debug' => true]));
        self::assertStringStartsWith(self::HEADER . "DEBUG: ERROR: Attributes missing for data_input_data and column data_template_data_id.\n", $tester->getDisplay());
        self::assertStringEndsWith("NOTE: Column widths adjusted on 0 Tables!\n", $tester->getDisplay());
    }

    public function testLegacyDebugNamesASkippedColumn(): void
    {
        $report = new WideningReport(false, false, [['table' => 't', 'column' => 'graph_id', 'event' => WideningEvent::Skipped, 'statement' => null]]);
        self::assertSame(
            ['NOTE: Fixing MediumInt Columns for Local Database', 'DEBUG: Column graph_id in Table t is generated or invisible, skipped.', 'NOTE: Column widths adjusted on 0 Tables!'],
            (new WidenIdColumnsLegacyArguments())->report($report, true)
        );
    }

    public function testLegacyHelpAndVersionMatchTheOriginal(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Help);
        $tester = $this->tester();
        self::assertSame(0, $tester->execute([]));
        self::assertSame($this->versionLine() . "\n"
            . "usage: fix_mediumint.php [--debug]\n\n"
            . "Options:\n"
            . "--debug    - Display verbose output during execution\n"
            . "--local    - Perform the action on the Remote Data Collector if run from there\n\n"
            . "This utility is used to increase the size of key Kadupul columns to accomodate\n"
            . "systems with over a million graphs and that have been in service for years.\n"
            . "After some long amount of time, Kadupul can run out of auto_increment fields.\n", $tester->getDisplay());
        $this->presentation->forLegacy(LegacyRequest::Version);
        self::assertSame(0, $tester->execute([]));
        self::assertSame($this->versionLine() . "\n", $tester->getDisplay());
    }

    public function testJsonReportsAFailedStatementWithExitOne(): void
    {
        $tester = $this->tester(false);
        self::assertSame(1, $tester->execute(['--json' => true]));
        self::assertSame(['status' => 'partial', 'database' => 'local', 'dry_run' => false, 'adjusted' => 1,
            'tables' => [['name' => 'a', 'result' => 'failed', 'statement' => 'ALTER TABLE `a` x']]], json_decode($tester->getDisplay(), true));
    }

    public function testJsonReportsASuccessfulRun(): void
    {
        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['--json' => true]));
        self::assertSame(['status' => 'ok', 'database' => 'local', 'dry_run' => false, 'adjusted' => 1,
            'tables' => [['name' => 'a', 'result' => 'widened', 'statement' => 'ALTER TABLE `a` x']]], json_decode($tester->getDisplay(), true));
    }

    public function testHumanDryRunListsTheStatement(): void
    {
        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('a: planned (ALTER TABLE `a` x)', $tester->getDisplay());
        self::assertStringContainsString('[OK] Planned id column changes in 1 tables.', $tester->getDisplay());
    }

    public function testHumanRunReportsWidenedAndFailedTables(): void
    {
        $tester = $this->tester();
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('a: widened', $tester->getDisplay());
        self::assertStringContainsString('[OK] Widened id columns in 1 tables.', $tester->getDisplay());
        $tester = $this->tester(false);
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('a: failed', $tester->getDisplay());
        self::assertStringContainsString('[WARNING] Widened id columns in 0 tables; 1 failed.', $tester->getDisplay());
    }

    public function testHumanRunWithNothingToWiden(): void
    {
        $widening = $this->createStub(ColumnWidening::class);
        $widening->method('catalog')->willReturn(new ColumnCatalog(WidenIdColumnsTest::freshSchema()));
        $widen = new WidenIdColumns(new MaintenanceTarget(new CliConsoleAccess($this->db, $this->db), $this->createStub(DatabaseMaintenance::class)), $widening, new SchemaChangeAudit($this->createStub(AuditTrail::class)));
        $tester = new CommandTester(new Command(null, new WidenIdColumnsCommand($widen, new InstallationVersion($this->root, $this->db, new Filesystem()), $this->presentation, new ResultRenderer(), new MockClock())));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('[OK] No id column needed widening.', $tester->getDisplay());
    }

    public function testAnOperatorWithoutTheUpgradeRealmIsRefused(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 26');
        // Realm 26 still has a holder, so the Settings/Utilities fallback stays closed.
        $this->db->executeStatement('INSERT INTO user_auth_realm VALUES (2, 26)');
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester();
        self::assertSame(1, $tester->execute([]));
        self::assertSame("ERROR: Unknown or unauthorized operator\n", $tester->getDisplay());
    }

    public function testAnEmptyOperatorIsAUsageError(): void
    {
        $tester = $this->tester();
        self::assertSame(Command::INVALID, $tester->execute(['--as' => ''], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('The --as option needs an operator name.', $tester->getErrorOutput());
    }

    public function testFailureTextNeverLeaksDatabaseDetails(): void
    {
        $tester = $this->tester(true, new \RuntimeException('SQLSTATE[HY000] secret-host:3306'));
        self::assertSame(1, $tester->execute(['--json' => true]));
        self::assertSame(['status' => 'failed', 'error' => 'Column widening failed'], json_decode($tester->getDisplay(), true));
    }

    public function testEveryOriginalFlagIsMapped(): void
    {
        $map = new WidenIdColumnsLegacyArguments();
        self::assertSame([['--debug' => true], null], $map->translate(['-d']));
        self::assertSame([['--debug' => true, '--local' => true], null], $map->translate(['--debug', '--local']));
        foreach (['--version', '-V', '-v'] as $flag) {
            self::assertSame(LegacyRequest::Version, $map->translate([$flag])[1]);
        }
        foreach (['--help', '-H', '-h'] as $flag) {
            self::assertSame(LegacyRequest::Help, $map->translate([$flag])[1]);
        }
        self::assertSame([['--as' => 'ops'], null], $map->translate(['--as=ops']));
        self::assertSame(['ERROR: Invalid Parameter --x', ''], $map->invalid('--x'));
        // No original flag took --dry-run, --json or --installer.
        foreach (['--dry-run', '--json', '--installer', '--as='] as $flag) {
            try {
                $map->translate([$flag]);
                self::fail($flag . ' was accepted.');
            } catch (InvalidLegacyArgument) {
            }
        }
    }

    public function testHelpListsNoHiddenShimOptions(): void
    {
        $process = new Process([PHP_BINARY, 'bin/console', 'help', 'kadupul:database:widen-id-columns'], dirname(__DIR__, 2), ['APP_ENV' => 'test', 'APP_DEBUG' => '1']);
        $process->mustRun();
        $help = $process->getOutput();
        foreach (['--local', '--debug', '--as=AS', '--dry-run', '--json'] as $expected) {
            self::assertStringContainsString($expected, $help);
        }
        self::assertStringNotContainsString('legacy-', $help);
    }
}
