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
use Kadupul\Platform\Application\Command\ConvertTables;
use Kadupul\Platform\Application\Command\MaintenanceTarget;
use Kadupul\Platform\Application\Command\SchemaChangeAudit;
use Kadupul\Platform\Application\Command\TableConversionStep;
use Kadupul\Platform\Application\Port\DatabaseMaintenance;
use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Application\Port\TableCatalog;
use Kadupul\Platform\Application\Port\TableConversion;
use Kadupul\Platform\Domain\Schema\TableStatus;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\ConvertTablesCommand;
use Kadupul\Platform\Infrastructure\Symfony\Console\ConvertTablesLegacyArguments;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class ConvertTablesCommandTest extends TestCase
{
    private const string HEADER = "NOTE: Repairing Tables for Local Database\n";

    private string $root;
    private Connection $db;
    private CliPresentation $presentation;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kadupul-convert-command-' . bin2hex(random_bytes(8));
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

    /** A primary with host (MyISAM, latin1, 2 rows) and settings (already InnoDB utf8mb4). */
    private function conversion(bool $ok = true, bool $filePerTable = true): TableConversion&MockObject
    {
        $conversion = $this->createMock(TableConversion::class);
        $conversion->method('baseTables')->willReturn(['host', 'settings']);
        $conversion->method('tableStatuses')->willReturn(new TableCatalog([
            'host' => new TableStatus('MyISAM', 'latin1_swedish_ci', 'Fixed', 2),
            'settings' => new TableStatus('InnoDB', 'utf8mb4_unicode_ci', 'Dynamic', 40),
        ]));
        $conversion->method('innodbEnabled')->willReturn(true);
        $conversion->method('filePerTable')->willReturn($filePerTable);
        $conversion->method('statement')->willReturnCallback(static fn(DatabaseTarget $target, string $table): string => 'ALTER TABLE `' . $table . '` ENGINE=InnoDB');
        $conversion->method('convert')->willReturn($ok);

        return $conversion;
    }

    private function tester(TableConversion $conversion): CommandTester
    {
        $maintenance = $this->createStub(DatabaseMaintenance::class);
        $convert = new ConvertTables(new MaintenanceTarget(new CliConsoleAccess($this->db, $this->db), $maintenance), $conversion, new TableConversionStep($conversion), new SchemaChangeAudit($this->createStub(AuditTrail::class)));
        // A year that is not the current one proves the version line reads the clock.
        $command = new ConvertTablesCommand($convert, new InstallationVersion($this->root, $this->db, new Filesystem()), $this->presentation, new ResultRenderer(), new MockClock('2031-06-01 00:00:00'));

        return new CommandTester(new Command(null, $command));
    }

    private function versionLine(): string
    {
        return 'Kadupul Database Conversion Utility, Version 1.3.0 (DB: 1.3.0), Copyright (C) 2004-2031 The Cacti Group';
    }

    private function help(): string
    {
        return $this->versionLine() . "\n"
            . "\nusage: convert_tables.php [--debug] [--innodb] [--utf8] [--latin1] [--table=N] [--size=N] [--rebuild] [--dynamic]\n\n"
            . "A utility to convert a Kadupul Database from MyISAM to the InnoDB table format.\n"
            . "MEMORY tables are not converted to InnoDB in this process.\n\n"
            . "Required (one or more):\n"
            . "-i | --innodb  - Convert any MyISAM tables to InnoDB\n"
            . "-u | --utf8    - Convert any non-UTF8 tables to utf8mb4_unicode_ci\n"
            . "-l | --latin1  - Convert any non-latin1 tables to latin1\n\n"
            . "Optional:\n"
            . "-t | --table=S - The name of a single table to change\n"
            . "-n | --skip-innodb=\"table1 table2 ...\" - Skip converting tables to InnoDB\n"
            . "-s | --size=N  - The largest table size in records to convert.  Default is 1,000,000 rows.\n"
            . "-r | --rebuild - Will compress/optimize existing InnoDB tables if found\n"
            . "     --dynamic - Convert a table to Dynamic row format if available\n"
            . "     --local   - Perform the action on the Remote Data Collector if run from there\n"
            . "-f | --force   - Proceed with conversion regardless of table size\n\n"
            . "-d | --debug   - Display verbose output during execution\n\n";
    }

    public function testLegacyInstallerCallMatchesTheOriginal(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester($this->conversion());
        self::assertSame(0, $tester->execute(['--table' => 'host', '--utf8' => true, '--innodb' => true, '--dynamic' => true]));
        $display = $tester->getDisplay();
        self::assertSame(self::HEADER . "Converting Database Tables to InnoDB and  utf8 with less than '1000000' Records\nConverting Table > 'host' Successful\n", $display);
        // lib/installer.php:3625-3627 dequeues the table only when both match.
        self::assertTrue(stripos($display, 'Converting table') !== false && stripos($display, 'Successful') !== false);
    }

    public function testAPendingPasswordAdminIsRefused(): void
    {
        $this->db->executeStatement("UPDATE user_auth SET must_change_password = 'on' WHERE id = 1");
        $this->presentation->forLegacy(LegacyRequest::Run);
        $conversion = $this->createMock(TableConversion::class);
        $conversion->expects(self::never())->method(self::anything());
        $tester = $this->tester($conversion);
        self::assertSame(1, $tester->execute(['--table' => 'host', '--utf8' => true, '--innodb' => true, '--dynamic' => true]));
        self::assertSame("ERROR: Unknown or unauthorized operator\n", $tester->getDisplay());
    }

    public function testLegacyFullRunReportsEveryBaseTable(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester($this->conversion());
        self::assertSame(0, $tester->execute(['--innodb' => true]));
        self::assertSame(self::HEADER . "Converting Database Tables to InnoDB with less than '1000000' Records\nConverting Table > 'host' Successful\nSkipping Table > 'settings'\n", $tester->getDisplay());
    }

    public function testLegacyTooLargeAndFailedLinesExitZero(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester($this->conversion(false));
        self::assertSame(0, $tester->execute(['--innodb' => true, '--size' => '2']));
        self::assertStringEndsWith("Skipping Table > 'host' too many rows '2'\nSkipping Table > 'settings'\n", $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--innodb' => true, '--size' => '2', '--force' => true]));
        self::assertStringEndsWith("Converting Table > 'host' Failed\nSkipping Table > 'settings'\n", $tester->getDisplay());
    }

    public function testLegacyFilePerTableRefusalEndsWithoutANewline(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester($this->conversion(true, false));
        self::assertSame(0, $tester->execute(['--innodb' => true]));
        self::assertSame(self::HEADER . "Converting Database Tables to InnoDB with less than '1000000' Records\ninnodb_file_per_table not enabled", $tester->getDisplay());
    }

    public function testLegacyValidationErrorsPrintHelpAndExitZero(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester($this->createStub(TableConversion::class));
        self::assertSame(0, $tester->execute(['--table' => 'host', '--skip-innodb' => 'settings', '--innodb' => true]));
        self::assertSame("ERROR: You can not specify a single table and skip tables at the same time.\n\n" . $this->help(), $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--table' => 'host']));
        self::assertSame("ERROR: Must select either UTF8, LATIN1 or InnoDB conversion.\n\n" . $this->help(), $tester->getDisplay());
    }

    public function testLegacyMissingSkipTable(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester($this->conversion());
        self::assertSame(0, $tester->execute(['--innodb' => true, '--skip-innodb' => 'hosts']));
        self::assertSame(self::HEADER . "ERROR: Skip Table hosts does not Exist.  Can not continue.\n\n" . $this->help(), $tester->getDisplay());
    }

    public function testLegacyHelpAndVersionMatchTheOriginal(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Help);
        $tester = $this->tester($this->createStub(TableConversion::class));
        self::assertSame(0, $tester->execute([]));
        self::assertSame($this->help(), $tester->getDisplay());
        $this->presentation->forLegacy(LegacyRequest::Version);
        self::assertSame(0, $tester->execute([]));
        self::assertSame($this->versionLine() . "\n", $tester->getDisplay());
    }

    public function testJsonReportsAPartialFailureWithExitOne(): void
    {
        $tester = $this->tester($this->conversion(false));
        self::assertSame(1, $tester->execute(['--innodb' => true, '--json' => true]));
        self::assertSame(['status' => 'partial', 'database' => 'local', 'dry_run' => false, 'tables' => [
            ['name' => 'host', 'result' => 'failed', 'rows' => 2, 'statement' => 'ALTER TABLE `host` ENGINE=InnoDB'],
            ['name' => 'settings', 'result' => 'skipped', 'rows' => 40],
        ]], json_decode($tester->getDisplay(), true));
    }

    public function testJsonNamesAStoppedRun(): void
    {
        $tester = $this->tester($this->conversion(true, false));
        self::assertSame(1, $tester->execute(['--innodb' => true, '--json' => true]));
        self::assertSame(['status' => 'failed', 'database' => 'local', 'error' => 'innodb_file_per_table is not enabled'], json_decode($tester->getDisplay(), true));
    }

    public function testHumanDryRunListsStatementsAndChangesNothing(): void
    {
        $conversion = $this->conversion();
        $conversion->expects(self::never())->method('convert');
        $tester = $this->tester($conversion);
        self::assertSame(0, $tester->execute(['--innodb' => true, '--dry-run' => true]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('host: planned (ALTER TABLE `host` ENGINE=InnoDB)', $display);
        self::assertStringContainsString('[OK] Planned 1 of 2 tables.', $display);
    }

    public function testInvalidInputUnderBinConsoleExitsTwo(): void
    {
        $tester = $this->tester($this->createStub(TableConversion::class));
        self::assertSame(Command::INVALID, $tester->execute(['--innodb' => true, '--size' => '1e3'], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('The --size option needs a whole number of rows.', $tester->getErrorOutput());
        self::assertSame(Command::INVALID, $tester->execute(['--json' => true]));
        self::assertSame(['status' => 'invalid', 'error' => 'no conversion selected'], json_decode($tester->getDisplay(), true));
        self::assertSame(Command::INVALID, $tester->execute(['--innodb' => true, '--as' => '']));
    }

    public function testAnOperatorWithoutTheUpgradeRealmIsRefused(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 26');
        $this->presentation->forLegacy(LegacyRequest::Run);
        $conversion = $this->createMock(TableConversion::class);
        $conversion->expects(self::never())->method(self::anything());
        $tester = $this->tester($conversion);
        self::assertSame(1, $tester->execute(['--innodb' => true]));
        self::assertSame("ERROR: Unknown or unauthorized operator\n", $tester->getDisplay());
    }

    public function testFailureTextNeverLeaksDatabaseDetails(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $conversion = $this->createStub(TableConversion::class);
        $conversion->method('tableStatuses')->willThrowException(new \Error("SQLSTATE[28000] Access denied for user 'cacti'@'db.internal'"));
        $tester = $this->tester($conversion);
        self::assertSame(1, $tester->execute(['--innodb' => true]));
        self::assertSame("ERROR: Table conversion failed\n", $tester->getDisplay());
    }

    public function testEveryOriginalFlagIsMapped(): void
    {
        $map = new ConvertTablesLegacyArguments();
        foreach (['-d', '--debug', '-r', '--rebuild', '--dynamic', '--local', '-i', '--innodb', '-l', '--latin1', '-f', '--force', '-u', '--utf8'] as $flag) {
            self::assertNull($map->translate([$flag])[1], $flag);
        }
        self::assertSame([['--size' => '10', '--table' => 'host', '--skip-innodb' => 'a b', '--as' => 'ops'], null], $map->translate(['-s=10', '-t=host', '-n=a b', '--as=ops']));
        foreach (['--version', '-V', '-v'] as $flag) {
            self::assertSame(LegacyRequest::Version, $map->translate([$flag])[1]);
        }
        foreach (['--help', '-H', '-h'] as $flag) {
            self::assertSame(LegacyRequest::Help, $map->translate([$flag])[1]);
        }
        foreach (['--installer', '--size=1e3', '-s=', '--table='] as $argument) {
            try {
                $map->translate([$argument]);
                self::fail('Expected ' . $argument . ' to be rejected');
            } catch (InvalidLegacyArgument $error) {
                self::assertSame($argument, $error->argument);
            }
        }
        self::assertSame(['ERROR: Invalid Parameter --x', ''], $map->invalid('--x'));
    }

    public function testHelpListsNoHiddenShimOptions(): void
    {
        $process = new Process([PHP_BINARY, 'bin/console', 'help', 'kadupul:database:convert-tables'], dirname(__DIR__, 2), ['APP_ENV' => 'test', 'APP_DEBUG' => '1']);
        $process->mustRun();
        $help = $process->getOutput();
        foreach (['--innodb', '--utf8', '--latin1', '--table=TABLE', '--skip-innodb', '--size=SIZE', '--dry-run', '--as=AS', '--json'] as $expected) {
            self::assertStringContainsString($expected, $help);
        }
        self::assertStringNotContainsString('installer', $help);
        self::assertStringNotContainsString('legacy-', $help);
    }
}
