<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\ConsoleOperator;
use Kadupul\Platform\Application\Command\AuditDatabase;
use Kadupul\Platform\Application\Command\InstallationAccessDenied;
use Kadupul\Platform\Application\Command\MaintenanceTarget;
use Kadupul\Platform\Application\Command\RemoteCollectorRefused;
use Kadupul\Platform\Application\Command\SchemaChangeAudit;
use Kadupul\Platform\Application\Port\AuditBaselineStore;
use Kadupul\Platform\Application\Port\AuditCatalog;
use Kadupul\Platform\Application\Port\DatabaseMaintenance;
use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Application\Port\InstallationUpgrade;
use Kadupul\Platform\Application\Port\SchemaAudit;
use Kadupul\Platform\Application\ReadModel\AlterResult;
use Kadupul\Platform\Application\ReadModel\AuditOutcome;
use Kadupul\Platform\Application\ReadModel\BaselineOutcome;
use Kadupul\Platform\Application\ReadModel\UpgradeOutput;
use Kadupul\Platform\Domain\Schema\AuditBaseline;
use Kadupul\Platform\Domain\Schema\AuditMode;
use Kadupul\Platform\Domain\Schema\BaselineColumn;
use Kadupul\Platform\Domain\Schema\BaselineIndex;
use Kadupul\Platform\Domain\Schema\InvalidAuditSchema;
use Kadupul\Platform\Domain\Schema\LiveTable;
use Kadupul\Platform\Domain\Schema\PluginSchemaChanges;
use Kadupul\Platform\Domain\Schema\TableStatus;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AuditDatabaseTest extends TestCase
{
    /** @var list<AuditEvent> */
    private array $events = [];

    private function audit(SchemaAudit $schema, AuditBaselineStore $baseline, ?InstallationUpgrade $upgrade = null, bool $allowed = true, bool $collector = false): AuditDatabase
    {
        $operator = $this->createStub(ConsoleOperator::class);
        $operator->method('actor')->willReturn(new Actor(1, 'admin'));
        $operator->method('canUpgradeInstallation')->willReturn($allowed);
        $maintenance = $this->createStub(DatabaseMaintenance::class);
        $maintenance->method('isRemoteCollector')->willReturn($collector);
        $trail = $this->createStub(AuditTrail::class);
        $trail->method('record')->willReturnCallback(function (AuditEvent $event): void {
            $this->events[] = $event;
        });

        return new AuditDatabase(new MaintenanceTarget($operator, $maintenance), $maintenance, $schema, $baseline, $upgrade ?? $this->createMock(InstallationUpgrade::class), new SchemaChangeAudit($trail));
    }

    /** poller_command lost its poller_id column's default and gained an index. */
    private static function catalog(): AuditCatalog
    {
        $columns = [['Field' => 'poller_id', 'Type' => 'int(10) unsigned', 'Null' => 'NO', 'Key' => '', 'Default' => null, 'Extra' => '']];
        $indexes = [['Table' => 'poller_command', 'Non_unique' => '1', 'Key_name' => 'stray', 'Seq_in_index' => '1', 'Column_name' => 'poller_id',
            'Collation' => 'A', 'Cardinality' => '0', 'Sub_part' => null, 'Packed' => null, 'Null' => '', 'Index_type' => 'BTREE', 'Comment' => '']];

        return new AuditCatalog([
            new LiveTable('poller_command', new TableStatus('InnoDB', 'utf8mb4_unicode_ci', 'Dynamic', 0), $columns, $indexes),
            new LiveTable('thold_data', new TableStatus('InnoDB', 'utf8mb4_unicode_ci', 'Dynamic', 0), [], []),
        ], PluginSchemaChanges::none());
    }

    private static function baseline(): AuditBaseline
    {
        return new AuditBaseline([new BaselineColumn('poller_command', 1, 'poller_id', 'int(10) unsigned', 'NO', '', '7', '')], []);
    }

    private function schema(string $version = '1.3.0'): SchemaAudit&MockObject
    {
        $schema = $this->createMock(SchemaAudit::class);
        $schema->method('codeVersion')->willReturn('1.3.0');
        $schema->method('databaseVersion')->willReturn($version);
        $schema->method('catalog')->willReturn(self::catalog());
        $schema->method('statement')->willReturn('ALTER TABLE `poller_command` typed');

        return $schema;
    }

    private function listing(AuditCatalog $catalog): SchemaAudit
    {
        $schema = $this->createStub(SchemaAudit::class);
        $schema->method('codeVersion')->willReturn('1.3.0');
        $schema->method('databaseVersion')->willReturn('1.3.0');
        $schema->method('catalog')->willReturn($catalog);

        return $schema;
    }

    private function store(?AuditBaseline $baseline = null): AuditBaselineStore&MockObject
    {
        $store = $this->createMock(AuditBaselineStore::class);
        $store->method('read')->willReturn($baseline ?? self::baseline());

        return $store;
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function events(): array
    {
        return array_map(static fn(AuditEvent $e): array => [$e->action, $e->targetType . ' ' . $e->targetId, $e->outcome], $this->events);
    }

    public function testAReportLoadsTheBaselineThenAuditsEveryTable(): void
    {
        $store = $this->store();
        $store->expects(self::once())->method('reset')->with(DatabaseTarget::Local)->willReturn(null);
        $store->expects(self::once())->method('replace')->with(DatabaseTarget::Local, self::baseline())->willReturn(true);
        $schema = $this->schema();
        $schema->expects(self::never())->method('alter');

        $report = $this->audit($schema, $store)(AuditMode::Report, false, null, true);

        self::assertSame([AuditOutcome::Completed, BaselineOutcome::Loaded], [$report->outcome, $report->baseline]);
        self::assertSame(['poller_command', 'thold_data'], array_map(static fn($table): string => $table->table, $report->tables));
        self::assertSame(["ERROR Col: 'poller_id', Attribute 'Default' invalid. Should be: '7', Is: '1'", "WARNING Index: 'stray', does not exist in default Kadupul.  Dropping."], $report->tables[0]->findings);
        self::assertSame([], $report->alters);
        self::assertSame([
            ['database.audit', 'database-maintenance local:audit-schema-reset', 'succeeded'],
            ['database.audit', 'database-table local:table_columns', 'succeeded'],
            ['database.audit', 'database-table local:table_indexes', 'succeeded'],
        ], $this->events());
    }

    public function testARepairSendsEachBuildableAlterAndAuditsIt(): void
    {
        $store = $this->store();
        $store->method('replace')->willReturn(true);
        $schema = $this->schema();
        $schema->expects(self::once())->method('alter')->with(DatabaseTarget::Local, self::callback(static fn($alter): bool => $alter->table === 'poller_command'), self::catalog()->table('poller_command'))->willReturn(false);

        $report = $this->audit($schema, $store)(AuditMode::Repair, false, null, true);

        self::assertSame([['table' => 'poller_command', 'legacy' => "ALTER TABLE `poller_command`\n   MODIFY COLUMN `poller_id` int(10) unsigned NOT NULL DEFAULT '7',\n   DROP INDEX stray,\n   ROW_FORMAT=Dynamic CHARSET=utf8mb4;",
            'result' => AlterResult::Failed, 'statement' => 'ALTER TABLE `poller_command` typed']], $report->alters);
        self::assertSame(['database.audit', 'database-table local:poller_command', 'failed'], $this->events()[3]);
        self::assertSame(1, $report->failed());
    }

    public function testAnUnbuildableAlterFailsWithoutAStatement(): void
    {
        $store = $this->store(new AuditBaseline([new BaselineColumn('poller_command', 1, 'poller_id', 'int(10) unsigned', 'NO', '', '7', '')], [
            new BaselineIndex('poller_command', 1, 'poller_id', 1, 'poller_id', 'A', 0, null, null, '', null, ''),
        ]));
        $store->method('replace')->willReturn(true);
        $schema = $this->schema();
        $schema->expects(self::never())->method('statement');
        $schema->expects(self::never())->method('alter');

        $report = $this->audit($schema, $store)(AuditMode::Repair, false, null, true);

        self::assertSame([AlterResult::Failed, null], [$report->alters[0]['result'], $report->alters[0]['statement']]);
        self::assertSame(['database.audit', 'database-table local:poller_command', 'failed'], $this->events()[3]);
    }

    public function testADryRunChangesNothingAndPlansTheAlters(): void
    {
        $store = $this->store();
        $store->expects(self::never())->method('reset');
        $store->expects(self::never())->method('replace');
        $schema = $this->schema();
        $schema->expects(self::never())->method('alter');

        $report = $this->audit($schema, $store)(AuditMode::Repair, false, null, false);

        self::assertSame([BaselineOutcome::Planned, true, AlterResult::Planned, 'ALTER TABLE `poller_command` typed'], [$report->baseline, $report->dryRun, $report->alters[0]['result'], $report->alters[0]['statement']]);
        self::assertSame([], $this->events);
    }

    public function testAMissingFileAuditsAgainstAnEmptyBaseline(): void
    {
        $store = $this->createMock(AuditBaselineStore::class);
        $store->method('read')->willReturn(null);
        $store->expects(self::once())->method('reset')->willReturn(null);
        $store->expects(self::never())->method('replace');

        $report = $this->audit($this->schema(), $store)(AuditMode::Report, false, null, true);

        self::assertSame(BaselineOutcome::FileMissing, $report->baseline);
        self::assertSame(['unknown', 'unknown'], array_map(static fn($table): string => $table->status->value, $report->tables));
    }

    /** The reset ran and is recorded; the reload did not, so no table event claims it did. */
    public function testAMissingOrUnparsableFileRecordsOnlyTheReset(): void
    {
        foreach ([null, new InvalidAuditSchema(3)] as $problem) {
            $this->events = [];
            $store = $this->createMock(AuditBaselineStore::class);
            $problem === null ? $store->method('read')->willReturn(null) : $store->method('read')->willThrowException($problem);
            $store->method('reset')->willReturn(null);
            $store->expects(self::never())->method('replace');

            $report = $this->audit($this->schema(), $store)(AuditMode::Repair, false, null, true);

            self::assertSame([['database.audit', 'database-maintenance local:audit-schema-reset', 'succeeded']], $this->events());
            self::assertSame(1, $report->failed());
        }
    }

    public function testAFailedReloadAuditsAgainstAnEmptyBaselineAndFails(): void
    {
        $store = $this->store();
        $store->method('reset')->willReturn(null);
        $store->expects(self::once())->method('replace')->willReturn(false);
        $schema = $this->schema();
        $schema->expects(self::never())->method('alter');

        $report = $this->audit($schema, $store)(AuditMode::Report, false, null, true);

        self::assertSame(BaselineOutcome::LoadFailed, $report->baseline);
        // An empty table_columns lists no table, as the script's failed load left it.
        self::assertSame(['unknown', 'unknown'], array_map(static fn($table): string => $table->status->value, $report->tables));
        self::assertSame(1, $report->failed());
        self::assertSame([
            ['database.audit', 'database-maintenance local:audit-schema-reset', 'succeeded'],
            ['database.audit', 'database-table local:table_columns', 'failed'],
            ['database.audit', 'database-table local:table_indexes', 'failed'],
        ], $this->events());
    }

    public function testAnUnparsableFileIsReportedWithItsLine(): void
    {
        $store = $this->createMock(AuditBaselineStore::class);
        $store->method('read')->willThrowException(new InvalidAuditSchema(42));
        $store->method('reset')->willReturn(null);
        $store->expects(self::never())->method('replace');

        $report = $this->audit($this->schema(), $store)(AuditMode::Create, false, null, true);

        self::assertSame([BaselineOutcome::Unparsable, 42], [$report->baseline, $report->unparsableLine]);
    }

    public function testACreateFailureStopsTheRun(): void
    {
        $store = $this->store();
        $store->method('reset')->willReturn('table_indexes');
        $schema = $this->schema();
        $schema->expects(self::never())->method('catalog');

        $report = $this->audit($schema, $store)(AuditMode::Repair, false, null, true);

        self::assertSame([BaselineOutcome::CreateFailed, 'table_indexes'], [$report->baseline, $report->uncreated]);
        self::assertSame('failed', $this->events()[0][2]);
    }

    public function testAnOlderDatabaseStopsWithoutUpgrade(): void
    {
        $store = $this->store();
        $store->expects(self::never())->method('reset');

        self::assertSame(AuditOutcome::UpgradeRequired, $this->audit($this->schema('1.2.31'), $store)(AuditMode::Report, false, null, true)->outcome);
    }

    public function testTheUpgradeRunsFirstAndIsAudited(): void
    {
        $upgrade = $this->createMock(InstallationUpgrade::class);
        $upgrade->expects(self::once())->method('run')->willReturn(new UpgradeOutput("upgraded\n", '', true));
        $store = $this->store();
        $store->method('replace')->willReturn(true);

        $report = $this->audit($this->schema('1.2.31'), $store, $upgrade)(AuditMode::Create, true, null, true);

        self::assertSame("upgraded\n", $report->upgrade->stdout);
        self::assertSame(['database.audit', 'database-maintenance local:upgrade', 'succeeded'], $this->events()[0]);
    }

    public function testAFailedUpgradeStopsBeforeTheModeTouchesAnything(): void
    {
        $upgrade = $this->createStub(InstallationUpgrade::class);
        $upgrade->method('run')->willReturn(new UpgradeOutput("half way\n", '', false));
        $store = $this->store();
        $store->expects(self::never())->method('reset');
        $store->expects(self::never())->method('replace');
        $schema = $this->schema('1.2.31');
        $schema->expects(self::never())->method('catalog');
        $schema->expects(self::never())->method('alter');

        $report = $this->audit($schema, $store, $upgrade)(AuditMode::Repair, true, null, true);

        self::assertSame([AuditOutcome::UpgradeFailed, AuditMode::Repair, false, "half way\n"], [$report->outcome, $report->mode, $report->dryRun, $report->upgrade?->stdout]);
        self::assertSame(1, $report->failed());
        self::assertSame([['database.audit', 'database-maintenance local:upgrade', 'failed']], $this->events());
    }

    public function testASuccessfulUpgradeStillRunsTheRepair(): void
    {
        $upgrade = $this->createStub(InstallationUpgrade::class);
        $upgrade->method('run')->willReturn(new UpgradeOutput('', '', true));
        $store = $this->store();
        $store->method('replace')->willReturn(true);
        $schema = $this->schema('1.2.31');
        $schema->expects(self::once())->method('alter')->willReturn(true);

        $report = $this->audit($schema, $store, $upgrade)(AuditMode::Repair, true, null, true);

        self::assertSame([AuditOutcome::Completed, AlterResult::Altered, 0], [$report->outcome, $report->alters[0]['result'], $report->failed()]);
        self::assertSame(['database.audit', 'database-maintenance local:upgrade', 'succeeded'], $this->events()[0]);
    }

    public function testADryRunOnlyPlansTheUpgrade(): void
    {
        $upgrade = $this->createMock(InstallationUpgrade::class);
        $upgrade->expects(self::never())->method('run');

        $report = $this->audit($this->schema('1.2.31'), $this->store(), $upgrade)(AuditMode::Create, true, null, false);

        self::assertTrue($report->upgradePlanned);
        self::assertNull($report->upgrade);
    }

    public function testLoadImportsAfterTheResetThenExports(): void
    {
        $store = $this->createMock(AuditBaselineStore::class);
        $store->expects(self::once())->method('reset')->willReturn(null);
        $store->expects(self::once())->method('import')->with(DatabaseTarget::Local, self::catalog())->willReturn(true);
        $store->method('dumpPath')->willReturn('/srv/kadupul/docs/audit_schema.sql');
        $store->expects(self::once())->method('export')->willReturn(false);

        $report = $this->audit($this->schema(), $store)(AuditMode::Load, false, null, true);

        self::assertSame([['poller_command', 'thold_data'], false], [$report->imported, $report->exported]);
        self::assertSame(['database.audit', 'database-maintenance local:audit-schema-export', 'failed'], $this->events()[3]);
        self::assertNull($report->baseline);
        // DbalAuditBaselineStore returns false for a timed-out or unwritable
        // dump too (DbalSchemaAuditTest), so either is recorded as this failure.
        self::assertCount(4, $this->events);
        self::assertSame(1, $report->failed());
    }

    public function testAFailedImportFailsTheLoad(): void
    {
        $store = $this->createMock(AuditBaselineStore::class);
        $store->method('reset')->willReturn(null);
        $store->expects(self::once())->method('import')->willReturn(false);
        $store->method('dumpPath')->willReturn('/srv/kadupul/docs/audit_schema.sql');
        $store->method('export')->willReturn(true);

        $report = $this->audit($this->schema(), $store)(AuditMode::Load, false, null, true);

        self::assertSame([BaselineOutcome::LoadFailed, true, 1], [$report->baseline, $report->exported, $report->failed()]);
        self::assertSame(['database.audit', 'database-table local:table_columns', 'failed'], $this->events()[1]);
    }

    /**
     * An applied --load creates the two audit tables before it lists the
     * schema; a dry run creates nothing, so it adds them where SHOW TABLES
     * would have listed them, and both runs list the same tables.
     */
    public function testADryRunLoadListsWhatAnAppliedLoadImports(): void
    {
        $status = new TableStatus('InnoDB', 'utf8mb4_unicode_ci', 'Dynamic', 0);
        $before = new AuditCatalog([new LiveTable('host', $status, [], []), new LiveTable('poller', $status, [], []), new LiveTable('user_auth', $status, [], [])], PluginSchemaChanges::none());
        $after = new AuditCatalog([new LiveTable('host', $status, [], []), new LiveTable('poller', $status, [], []), new LiveTable('table_columns', $status, [], []),
            new LiveTable('table_indexes', $status, [], []), new LiveTable('user_auth', $status, [], [])], PluginSchemaChanges::none());
        $store = $this->createStub(AuditBaselineStore::class);
        $store->method('reset')->willReturn(null);
        $store->method('import')->willReturn(true);

        $dry = $this->audit($this->listing($before), $store)(AuditMode::Load, false, null, false);
        $applied = $this->audit($this->listing($after), $store)(AuditMode::Load, false, null, true);

        self::assertSame(['host', 'poller', 'table_columns', 'table_indexes', 'user_auth'], $dry->imported);
        self::assertSame($applied->imported, $dry->imported);
        // Already present, they are not listed twice.
        self::assertSame($applied->imported, $this->audit($this->listing($after), $store)(AuditMode::Load, false, null, false)->imported);
    }

    public function testARemoteCollectorIsRefusedBeforeAnyLookup(): void
    {
        $store = $this->createMock(AuditBaselineStore::class);
        $store->expects(self::never())->method('read');
        $store->expects(self::never())->method('reset');
        $schema = $this->createMock(SchemaAudit::class);
        $schema->expects(self::never())->method('databaseVersion');
        $schema->expects(self::never())->method('catalog');
        $audit = $this->audit($schema, $store, null, true, true);

        self::assertTrue($audit->refusesThisCollector());
        self::assertFalse($this->audit($schema, $store)->refusesThisCollector());
        foreach ([true => 'database.audit', false => 'database.audit.dry-run'] as $apply => $action) {
            $this->events = [];
            try {
                $audit(AuditMode::Repair, false, null, (bool) $apply);
                self::fail('Expected a refusal');
            } catch (RemoteCollectorRefused $refused) {
                self::assertSame('The audit runs on the main data collector only.', $refused->getMessage());
                self::assertSame([[$action, 'database local', 'denied']], $this->events());
            }
        }
    }

    public function testARefusalIsAuditedAndRunsNothing(): void
    {
        $store = $this->createMock(AuditBaselineStore::class);
        $store->expects(self::never())->method('read');

        try {
            $this->audit($this->schema(), $store, null, false)(AuditMode::Repair, false, null, false);
            self::fail('Expected a refusal');
        } catch (InstallationAccessDenied) {
            self::assertSame([['database.audit.dry-run', 'database local', 'denied']], $this->events());
        }
    }

    public function testNoModeStopsAfterTheVersionCheck(): void
    {
        $store = $this->createMock(AuditBaselineStore::class);
        $store->expects(self::never())->method('read');

        self::assertSame(AuditOutcome::NoMode, $this->audit($this->schema(), $store)(null, false, null, true)->outcome);
    }
}
