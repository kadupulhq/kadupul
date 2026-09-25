<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Platform\Application\Command\AuditDatabase;
use Kadupul\Platform\Application\Command\MaintenanceTarget;
use Kadupul\Platform\Application\Port\AuditBaselineStore;
use Kadupul\Platform\Application\Port\AuditCatalog;
use Kadupul\Platform\Application\Port\DatabaseMaintenance;
use Kadupul\Platform\Application\Port\InstallationUpgrade;
use Kadupul\Platform\Application\Port\SchemaAudit;
use Kadupul\Platform\Application\ReadModel\AlterResult;
use Kadupul\Platform\Application\ReadModel\AuditOutcome;
use Kadupul\Platform\Application\ReadModel\AuditReport;
use Kadupul\Platform\Application\ReadModel\BaselineOutcome;
use Kadupul\Platform\Application\ReadModel\UpgradeOutput;
use Kadupul\Platform\Domain\Schema\AuditBaseline;
use Kadupul\Platform\Domain\Schema\AuditMode;
use Kadupul\Platform\Domain\Schema\BaselineColumn;
use Kadupul\Platform\Domain\Schema\LiveTable;
use Kadupul\Platform\Domain\Schema\PluginSchemaChanges;
use Kadupul\Platform\Domain\Schema\TableStatus;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\AuditDatabaseCommand;
use Kadupul\Platform\Infrastructure\Symfony\Console\AuditDatabaseLegacyArguments;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\InvalidLegacyArgument;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use Kadupul\Tests\Fixtures\MaintenanceOperator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class AuditDatabaseCommandTest extends TestCase
{
    use ConsoleOperatorDatabase;
    use MaintenanceOperator;

    private const string SEPARATOR = AuditDatabaseLegacyArguments::SEPARATOR;
    private const string VERSION_LINE = 'Kadupul Database Audit Utility, Version 1.3.0 (DB: 1.3.0), Copyright (C) 2004-2031 The Cacti Group';

    private string $root;
    private Connection $db;
    private CliPresentation $presentation;

    protected function setUp(): void
    {
        $this->root = $this->installationRoot('kadupul-audit-command-');
        // User 1, admin, holds Console Access (8) and Installation/Upgrades (26).
        $this->db = $this->operatorDatabase(8, 26);
        $this->presentation = new CliPresentation();
    }

    /** @param bool|list<bool> $collector what isRemoteCollector() answers, call by call */
    private function tester(?SchemaAudit $schema = null, ?AuditBaselineStore $store = null, bool|array $collector = false, ?InstallationUpgrade $upgrade = null): CommandTester
    {
        $maintenance = $this->createStub(DatabaseMaintenance::class);
        $maintenance->method('isRemoteCollector')->willReturn(...(is_array($collector) ? $collector : [$collector]));
        $audit = new AuditDatabase(
            new MaintenanceTarget(new CliConsoleAccess($this->db, $this->db), $maintenance),
            $maintenance,
            $schema ?? $this->schema(),
            $store ?? $this->store(),
            $upgrade ?? $this->createStub(InstallationUpgrade::class),
            $this->recordingAudit(),
        );
        // A year that is not the current one proves the version line reads the clock.
        $version = new InstallationVersion($this->root, $this->db, new Filesystem(), new MockClock('2031-06-01 00:00:00'));

        return new CommandTester(new Command(null, new AuditDatabaseCommand($audit, $version, $this->presentation, new ResultRenderer())));
    }

    /** host lost a default; settings is clean. */
    private function schema(bool $ok = true, string $version = '1.3.0'): SchemaAudit
    {
        $schema = $this->createStub(SchemaAudit::class);
        $schema->method('codeVersion')->willReturn('1.3.0');
        $schema->method('databaseVersion')->willReturn($version);
        $schema->method('catalog')->willReturn(new AuditCatalog([
            new LiveTable('host', new TableStatus('MyISAM', 'latin1_swedish_ci', 'Dynamic', 1), [['Field' => 'ping', 'Type' => 'int(10) unsigned', 'Null' => 'NO', 'Key' => '', 'Default' => null, 'Extra' => '']], []),
            new LiveTable('settings', new TableStatus('InnoDB', 'utf8mb4_unicode_ci', 'Dynamic', 1), [['Field' => 'name', 'Type' => 'varchar(75)', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => '', 'Extra' => '']], []),
        ], PluginSchemaChanges::none()));
        $schema->method('statement')->willReturn('ALTER TABLE `host` MODIFY COLUMN `ping` int(10) unsigned NOT NULL DEFAULT \'400\', ENGINE=InnoDB ROW_FORMAT=Dynamic CHARSET=latin1');
        $schema->method('alter')->willReturn($ok);

        return $schema;
    }

    private function store(?string $uncreated = null, bool|\Throwable $export = true): AuditBaselineStore
    {
        $store = $this->createStub(AuditBaselineStore::class);
        $store->method('read')->willReturn(new AuditBaseline([
            new BaselineColumn('host', 1, 'ping', 'int(10) unsigned', 'NO', '', '400', ''),
            new BaselineColumn('settings', 1, 'name', 'varchar(75)', 'NO', 'PRI', '', ''),
        ], []));
        $store->method('reset')->willReturn($uncreated);
        $store->method('replace')->willReturn(true);
        $store->method('import')->willReturn(true);
        $store->method('dumpPath')->willReturn($this->root . '/docs/audit_schema.sql');
        if ($export instanceof \Throwable) {
            $store->method('export')->willThrowException($export);
        } else {
            $store->method('export')->willReturn($export);
        }

        return $store;
    }

    private static function checking(string $table): string
    {
        return sprintf('Checking Table: %-45s', "'" . $table . "'");
    }

    /** @return list<string> */
    private function help(): array
    {
        return [self::VERSION_LINE, ...(new AuditDatabaseLegacyArguments())->help()];
    }

    /** @return list<array{string, string, string}> */
    private function events(): array
    {
        return array_map(static fn(AuditEvent $e): array => [$e->action, $e->targetType . ' ' . $e->targetId, $e->outcome], $this->events);
    }

    public function testLegacyReportMatchesTheOriginalLayout(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester();

        self::assertSame(0, $tester->execute(['--report' => true]));
        self::assertSame(implode("\n", [
            'SUCCESS: Loaded the Audit Schema',
            self::SEPARATOR,
            self::checking('host'),
            "ERROR Col: 'ping', Attribute 'Default' invalid. Should be: '400', Is: '1'",
            '',
            'ERRORS: 1, WARNINGS: 0',
            self::SEPARATOR,
            self::checking('settings') . ' - Clean',
            self::SEPARATOR,
            'ERRORS are fixable using the --repair option.  WARNINGS will not be repaired',
            'due to ambiguous use of the column.',
            self::SEPARATOR,
        ]) . "\n", $tester->getDisplay());
    }

    public function testLegacyRepairPrintsAFailureWithTheOriginalText(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester($this->schema(false));

        self::assertSame(0, $tester->execute(['--repair' => true, '--alters' => true]));
        self::assertSame(implode("\n", [
            '-- SUCCESS: Loaded the Audit Schema',
            sprintf('-- Scanning Table: %-45s', "'host'") . ' - Completed',
            sprintf('-- Scanning Table: %-45s', "'settings'") . ' - Completed',
            self::SEPARATOR,
            'Executing Alter for Table : host - Failed',
            'ALTER TABLE `host`',
            "   MODIFY COLUMN `ping` int(10) unsigned NOT NULL DEFAULT '400',",
            '   ENGINE=InnoDB ROW_FORMAT=Dynamic CHARSET=latin1;',
            self::SEPARATOR,
            'Repair Completed!  0 Alters succeeded and 1 failed!',
        ]) . "\n", $tester->getDisplay());
        // The reset, the two reloaded audit tables, then the one alter.
        self::assertSame([
            ['database.audit', 'database-maintenance local:audit-schema-reset', 'succeeded'],
            ['database.audit', 'database-table local:table_columns', 'succeeded'],
            ['database.audit', 'database-table local:table_indexes', 'succeeded'],
            ['database.audit', 'database-table local:host', 'failed'],
        ], $this->events());
    }

    public function testLegacyRepairThatSucceedsSaysSo(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester();

        self::assertSame(0, $tester->execute(['--repair' => true]));
        self::assertStringEndsWith("\n" . self::SEPARATOR . "\nExecuting Alter for Table : host - Success\n" . self::SEPARATOR . "\nRepair Completed!  All 1 Alters succeeded!\n", $tester->getDisplay());
    }

    public function testLegacyAltersProposesWithoutRunning(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester();

        self::assertSame(0, $tester->execute(['--alters' => true]));
        self::assertStringContainsString(self::SEPARATOR . "\n-- Proposed Alter for Table : host\n\nALTER TABLE `host`\n", $tester->getDisplay());
        self::assertStringEndsWith("\n" . self::SEPARATOR . "\n-- Repair Completed!  No changes performed.\n", $tester->getDisplay());
    }

    /**
     * The shim refuses --dry-run before AuditDatabase runs, so a Repair with an
     * unsent (Planned) alter cannot happen today. This pins the defensive guard
     * that stops it anyway: without it, "Repair Completed!" below would describe
     * a table the loop above only proposed, exactly as ConvertTablesLegacyArguments
     * already refuses for convert-tables.
     */
    public function testLegacyRepairRefusesAPlannedAlterAsADryRunLeak(): void
    {
        $report = new AuditReport(
            AuditOutcome::Completed,
            AuditMode::Repair,
            false,
            baseline: BaselineOutcome::Loaded,
            alters: [['table' => 'host', 'legacy' => 'ALTER TABLE `host`', 'result' => AlterResult::Planned, 'statement' => null]],
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A cli/ shim cannot ask for a dry run.');

        (new AuditDatabaseLegacyArguments())->report($report, false, '');
    }

    public function testLegacyCreateFailureEndsWithoutANewline(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester(null, $this->store('table_columns'));

        self::assertSame(0, $tester->execute(['--create' => true]));
        self::assertSame("Failed to create 'table_columns'", $tester->getDisplay());
    }

    public function testLegacyLoadListsTheImportAndTheExport(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester();

        self::assertSame(0, $tester->execute(['--load' => true]));
        self::assertSame(implode("\n", [
            'Importing Table: host - Done',
            'Importing Table: settings - Done',
            '',
            'Exporting Table Audit Table Creation Logic to ' . $this->root . '/docs/audit_schema.sql',
            'Finished Creating Audit Schema',
            '',
        ]) . "\n", $tester->getDisplay());
    }

    public function testLegacyCollectorIsRefusedEvenForHelp(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Help);

        $tester = $this->tester(null, null, true);

        self::assertSame(1, $tester->execute([]));
        self::assertSame("FATAL: This utility is designed for the main Data Collector only\n", $tester->getDisplay());
    }

    public function testTheUseCaseRefusalOfACollectorPrintsTheSameMessage(): void
    {
        // The command's own check sees a primary; the use case then sees a
        // collector, as it would if the configuration changed between them.
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester(null, null, [false, true]);

        self::assertSame(1, $tester->execute(['--repair' => true]));
        self::assertSame("FATAL: This utility is designed for the main Data Collector only\n", $tester->getDisplay());
        self::assertSame([['database.audit', 'database local', 'denied']], $this->events());
    }

    public function testJsonCollectorRefusal(): void
    {
        $tester = $this->tester(null, null, true);

        self::assertSame(1, $tester->execute(['--report' => true, '--json' => true]));
        self::assertSame('{"status":"failed","error":"main data collector only"}' . "\n", $tester->getDisplay());
    }

    public function testLegacyUsageAndNoModeMatchTheOriginal(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Usage);
        $usage = $this->tester();
        self::assertSame(1, $usage->execute([]));
        self::assertSame(implode("\n", $this->help()) . "\n", $usage->getDisplay());

        $this->presentation = new CliPresentation();
        $this->presentation->forLegacy(LegacyRequest::Run);
        $none = $this->tester();
        self::assertSame(0, $none->execute(['--upgrade' => true]));
        self::assertSame(implode("\n", $this->help()) . "\n", $none->getDisplay());
    }

    public function testLegacyVersion(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Version);

        self::assertSame(0, ($tester = $this->tester())->execute([]));
        self::assertSame(self::VERSION_LINE . "\n", $tester->getDisplay());
    }

    public function testLegacyUpgradeRequiredExitsOne(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester($this->schema(true, '1.2.31'));

        self::assertSame(1, $tester->execute(['--report' => true]));
        self::assertSame("WARNING: Kadupul must be upgraded first.  Use the --upgrade option to perform that upgrade\n", $tester->getDisplay());
    }

    public function testLegacyUpgradeOutputComesFirstAndItsErrorsGoToStderr(): void
    {
        $upgrade = $this->createStub(InstallationUpgrade::class);
        $upgrade->method('run')->willReturn(new UpgradeOutput("01/02/2031 03:04:05 - UPGRADE NOTE: Upgrading Kadupul, this will take a few minutes.\n", "PHP Warning: x\n", true));
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester($this->schema(true, '1.2.31'), null, false, $upgrade);

        self::assertSame(0, $tester->execute(['--upgrade' => true, '--create' => true], ['capture_stderr_separately' => true]));
        self::assertSame("01/02/2031 03:04:05 - UPGRADE NOTE: Upgrading Kadupul, this will take a few minutes.\nSUCCESS: Loaded the Audit Schema\n", $tester->getDisplay());
        self::assertSame("PHP Warning: x\n", $tester->getErrorOutput());
        self::assertSame(['database.audit', 'database-maintenance local:upgrade', 'succeeded'], $this->events()[0]);
    }

    /** A database behind the code, whose tables a failed upgrade must leave unread and unaltered. */
    private function unaltered(): SchemaAudit
    {
        $schema = $this->createMock(SchemaAudit::class);
        $schema->method('codeVersion')->willReturn('1.3.0');
        $schema->method('databaseVersion')->willReturn('1.2.31');
        $schema->expects(self::never())->method('catalog');
        $schema->expects(self::never())->method('alter');

        return $schema;
    }

    private function failedUpgrade(): InstallationUpgrade
    {
        $upgrade = $this->createStub(InstallationUpgrade::class);
        $upgrade->method('run')->willReturn(new UpgradeOutput("01/02/2031 03:04:05 - UPGRADE WARNING: Kadupul Upgrade Encountered Errors.\n", "PHP Warning: x\n", false));

        return $upgrade;
    }

    public function testLegacyFailedUpgradeSaysSoAndSendsNoAlter(): void
    {
        $this->presentation->forLegacy(LegacyRequest::Run);
        $tester = $this->tester($this->unaltered(), null, false, $this->failedUpgrade());

        self::assertSame(1, $tester->execute(['--upgrade' => true, '--repair' => true], ['capture_stderr_separately' => true]));
        self::assertSame("01/02/2031 03:04:05 - UPGRADE WARNING: Kadupul Upgrade Encountered Errors.\nFATAL: Kadupul Upgrade Failed.  The audit was not run.\n", $tester->getDisplay());
        self::assertSame("PHP Warning: x\n", $tester->getErrorOutput());
        self::assertSame([['database.audit', 'database-maintenance local:upgrade', 'failed']], $this->events());
    }

    public function testJsonFailedUpgradeIsAFailureAndSendsNoAlter(): void
    {
        $tester = $this->tester($this->unaltered(), null, false, $this->failedUpgrade());

        self::assertSame(1, $tester->execute(['--upgrade' => true, '--repair' => true, '--json' => true]));
        $json = json_decode($tester->getDisplay(), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(['status' => 'failed', 'database' => 'local', 'dry_run' => false, 'mode' => 'repair', 'upgrade' => 'failed', 'error' => 'upgrade failed'], $json);
    }

    public function testHumanFailedUpgradeGoesToStderr(): void
    {
        $tester = $this->tester($this->unaltered(), null, false, $this->failedUpgrade());

        self::assertSame(1, $tester->execute(['--upgrade' => true, '--repair' => true], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('The upgrade failed, so the audit did not run.', $tester->getErrorOutput());
    }

    public function testJsonSuccessfulUpgradeStillRunsTheRepair(): void
    {
        $upgrade = $this->createStub(InstallationUpgrade::class);
        $upgrade->method('run')->willReturn(new UpgradeOutput('', '', true));
        $tester = $this->tester($this->schema(true, '1.2.31'), null, false, $upgrade);

        self::assertSame(0, $tester->execute(['--upgrade' => true, '--repair' => true, '--json' => true]));
        $json = json_decode($tester->getDisplay(), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(['ok', 'upgraded', 'altered'], [$json['status'], $json['upgrade'], $json['alters'][0]['result']]);
    }

    public function testJsonReportsAltersAndExitsOneOnAFailure(): void
    {
        $tester = $this->tester($this->schema(false));

        self::assertSame(1, $tester->execute(['--repair' => true, '--json' => true]));
        $json = json_decode($tester->getDisplay(), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(['status', 'database', 'dry_run', 'mode', 'upgrade', 'baseline', 'tables', 'alters', 'imported', 'exported'], array_keys($json));
        self::assertSame(['partial', 'local', false, 'repair', 'loaded', 'none'], [$json['status'], $json['database'], $json['dry_run'], $json['mode'], $json['baseline'], $json['upgrade']]);
        self::assertSame([['table' => 'host', 'result' => 'failed', 'statement' => 'ALTER TABLE `host` MODIFY COLUMN `ping` int(10) unsigned NOT NULL DEFAULT \'400\', ENGINE=InnoDB ROW_FORMAT=Dynamic CHARSET=latin1']], $json['alters']);
    }

    public function testJsonDryRunPlansTheUpgradeAndTheAltersAndRecordsNothing(): void
    {
        $upgrade = $this->createMock(InstallationUpgrade::class);
        $upgrade->expects(self::never())->method('run');
        $tester = $this->tester($this->schema(true, '1.2.31'), null, false, $upgrade);

        self::assertSame(0, $tester->execute(['--repair' => true, '--upgrade' => true, '--dry-run' => true, '--json' => true]));
        $json = json_decode($tester->getDisplay(), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(['ok', true, 'planned', 'planned', 'planned'], [$json['status'], $json['dry_run'], $json['upgrade'], $json['baseline'], $json['alters'][0]['result']]);
        self::assertSame([], $this->events);
    }

    public function testHumanReportListsTheTablesWithProblems(): void
    {
        $tester = $this->tester();

        self::assertSame(0, $tester->execute(['--report' => true]));
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/host\s+1\s+0/', $display);
        self::assertStringContainsString('Audited 2 tables, 1 with problems.', $display);
    }

    public function testHumanRepairFailureWarnsAndExitsOne(): void
    {
        $tester = $this->tester($this->schema(false));

        self::assertSame(1, $tester->execute(['--repair' => true]));
        self::assertStringContainsString('Audited 2 tables, 1 with problems; 1 failed.', $tester->getDisplay());
    }

    public function testHumanUpgradeRequiredGoesToStderr(): void
    {
        $tester = $this->tester($this->schema(true, '1.2.31'));

        self::assertSame(1, $tester->execute(['--report' => true], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('The database is behind the code; add --upgrade.', $tester->getErrorOutput());
    }

    public function testNoModeUnderBinConsoleExitsTwo(): void
    {
        $tester = $this->tester();

        self::assertSame(2, $tester->execute(['--json' => true]));
        self::assertSame('{"status":"invalid","error":"no mode selected"}' . "\n", $tester->getDisplay());
    }

    public function testAnEmptyOperatorIsRefusedBeforeAnything(): void
    {
        $store = $this->createMock(AuditBaselineStore::class);
        $store->expects(self::never())->method('read');
        $this->presentation->forLegacy(LegacyRequest::Run);

        $tester = $this->tester(null, $store);

        self::assertSame(2, $tester->execute(['--report' => true, '--as' => '']));
        self::assertSame("ERROR: Invalid Parameter --as=\n", $tester->getDisplay());
    }

    public function testAnOperatorWithoutRealm26IsDeniedBeforeAnything(): void
    {
        // Settings/Utilities (15) is not enough once anyone holds realm 26: the
        // audit can run the upgrade. With nobody holding it, CliConsoleAccess
        // lets realm 15 in, as the web installer does.
        $this->db = $this->operatorDatabase(8, 15);
        $this->db->insert('user_auth_realm', ['user_id' => 2, 'realm_id' => 26]);
        $store = $this->createMock(AuditBaselineStore::class);
        $store->expects(self::never())->method('read');
        $store->expects(self::never())->method('reset');
        $this->presentation->forLegacy(LegacyRequest::Run);

        $tester = $this->tester(null, $store);

        self::assertSame(1, $tester->execute(['--repair' => true]));
        self::assertSame("ERROR: Unknown or unauthorized operator\n", $tester->getDisplay());
        self::assertSame([['database.audit', 'database local', 'denied']], $this->events());
    }

    public function testAFailedExportEndsWithTheOriginalErrorLine(): void
    {
        // DbalAuditBaselineStore returns false for a failed, timed-out or unwritable dump.
        $this->presentation->forLegacy(LegacyRequest::Run);
        $legacy = $this->tester(null, $this->store(null, false));
        self::assertSame(0, $legacy->execute(['--load' => true]));
        self::assertStringEndsWith("\nFinished Creating Audit Schema with ERROR\n\n", $legacy->getDisplay());

        $this->presentation = new CliPresentation();
        $json = $this->tester(null, $this->store(null, false));
        self::assertSame(1, $json->execute(['--load' => true, '--json' => true]));
        self::assertSame(['partial', false], array_values(array_intersect_key(json_decode($json->getDisplay(), true, 8, JSON_THROW_ON_ERROR), ['status' => 0, 'exported' => 0])));
        self::assertSame(['database.audit', 'database-maintenance local:audit-schema-export', 'failed'], $this->events()[array_key_last($this->events)]);
    }

    public function testAnErrorFailsTheRunWithoutItsText(): void
    {
        $error = new ProcessTimedOutException(new Process(['mariadb-dump', '--host=db.internal', 'cacti']), ProcessTimedOutException::TYPE_GENERAL);
        foreach ([[LegacyRequest::Run, [], "ERROR: Database audit failed\n"], [null, ['--json' => true], '{"status":"failed","error":"Database audit failed"}' . "\n"]] as [$legacy, $flags, $expected]) {
            $this->presentation = new CliPresentation();
            if ($legacy !== null) {
                $this->presentation->forLegacy($legacy);
            }
            $tester = $this->tester(null, $this->store(null, $error));

            self::assertSame(1, $tester->execute(['--load' => true] + $flags));
            self::assertSame($expected, $tester->getDisplay());
        }
    }

    public function testHumanCreateAndLoadSayWhatHappenedToTheAuditTables(): void
    {
        foreach ([
            [['--create' => true], 'Reloaded the audit tables from docs/audit_schema.sql.'],
            [['--create' => true, '--dry-run' => true], 'Read docs/audit_schema.sql; the audit tables were not reloaded.'],
            // SymfonyStyle wraps the temporary path, so only the start is compared.
            [['--load' => true], 'Imported 2 tables and exported them to /'],
        ] as [$flags, $expected]) {
            $tester = $this->tester();
            self::assertSame(0, $tester->execute($flags));
            self::assertStringContainsString($expected, preg_replace('/\s+/', ' ', $tester->getDisplay()));
            self::assertStringNotContainsString('Audited', $tester->getDisplay());
        }
        $failed = $this->tester(null, $this->store('table_columns'));
        self::assertSame(1, $failed->execute(['--create' => true]));
        self::assertStringContainsString('Could not create the table_columns table; 1 failed.', preg_replace('/\s+/', ' ', $failed->getDisplay()));
    }

    /**
     * The shim's parser against PHP's own getopt(), given the options
     * audit_database.php:28-42 declared, for every argument list here.
     *
     * @return list<list<string>>
     */
    private static function argumentLists(): array
    {
        return [['--bogus'], ['--report'], ['--report=x'], ['--report='], ['-vh'], ['-hv'], ['-xv'], ['-V1'], ['--rep'], ['x', '--report'],
            ['--report', 'x', '--repair'], ['--report', '--report'], ['--help', '--report'], ['--report', '--help'], ['--', '--report'],
            ['-', '--report'], ['---report'], ['--version='], ['--help=1'], ['--=x', '--report'], ['-Vh', '--report'], ['--REPORT'],
            ['--alters', '--load', '--create', '--upgrade', '--repair']];
    }

    public function testTheParserMatchesGetopt(): void
    {
        $probe = 'echo json_encode(getopt("VvHh", ["create", "load", "report", "upgrade", "repair", "alters", "version", "help"]), JSON_FORCE_OBJECT);';
        foreach (self::argumentLists() as $argv) {
            $process = new Process([PHP_BINARY, '-r', $probe, '--', ...$argv]);
            $process->mustRun();
            $options = json_decode($process->getOutput(), true, 4, JSON_THROW_ON_ERROR);
            // What the script's foreach did with them: the first version or help exits.
            $special = null;
            $modes = [];
            foreach (array_keys($options) as $option) {
                if (in_array($option, ['version', 'V', 'v', 'help', 'H', 'h'], true)) {
                    $special = in_array($option, ['version', 'V', 'v'], true) ? LegacyRequest::Version : LegacyRequest::Help;
                    break;
                }
                $modes['--' . $option] = true;
            }
            self::assertSame($special === null ? [$modes, null] : [[], $special], (new AuditDatabaseLegacyArguments())->translate($argv), implode(' ', $argv));
        }
        self::assertSame([[], LegacyRequest::Usage], (new AuditDatabaseLegacyArguments())->translate([]));
        self::assertSame([['--report' => true, '--as' => 'ops'], null], (new AuditDatabaseLegacyArguments())->translate(['--report', '--as=ops']));
        // Only --as=NAME carries a name, as in the other shims, so a following
        // option is never taken as the operator.
        foreach ([['--as', '--report'], ['--report', '--as', 'ops'], ['--as']] as $argv) {
            try {
                (new AuditDatabaseLegacyArguments())->translate($argv);
                self::fail('Accepted ' . implode(' ', $argv));
            } catch (InvalidLegacyArgument $error) {
                self::assertSame('--as', $error->argument);
            }
        }
    }

    public function testHelpListsNoHiddenShimOptions(): void
    {
        $process = new Process([PHP_BINARY, 'bin/console', 'help', 'kadupul:database:audit'], dirname(__DIR__, 2), ['APP_ENV' => 'test', 'APP_DEBUG' => '1']);
        $process->mustRun();
        $help = $process->getOutput();
        foreach (['--report', '--repair', '--alters', '--upgrade', '--create', '--load', '--dry-run', '--as=AS', '--json'] as $expected) {
            self::assertStringContainsString($expected, $help);
        }
        self::assertStringNotContainsString('legacy', $help);
    }
}
