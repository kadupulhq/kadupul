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
use Kadupul\Platform\Application\Command\InstallationAccessDenied;
use Kadupul\Platform\Application\Command\MaintenanceTarget;
use Kadupul\Platform\Application\Command\SchemaChangeAudit;
use Kadupul\Platform\Application\Command\WidenIdColumns;
use Kadupul\Platform\Application\Port\ColumnCatalog;
use Kadupul\Platform\Application\Port\ColumnWidening;
use Kadupul\Platform\Application\Port\DatabaseMaintenance;
use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Application\ReadModel\WideningEvent;
use Kadupul\Platform\Domain\Schema\ColumnDefinition;
use Kadupul\Platform\Domain\Schema\IdColumns;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class WidenIdColumnsTest extends TestCase
{
    /** @var list<AuditEvent> */
    private array $events = [];
    /** @var list<string> statements widen() received */
    private array $sent = [];

    /**
     * Every named column already int(10) unsigned; rrdcheck keeps its mediumint,
     * as cacti.sql has it. Public: WidenIdColumnsCommandTest starts from it too.
     *
     * @return array<string, list<ColumnDefinition>>
     */
    public static function freshSchema(): array
    {
        $schema = [];
        foreach (IdColumns::KNOWN as $table => $columns) {
            $schema[$table] = array_map(
                static fn(string $column): ColumnDefinition => new ColumnDefinition($column, 'int(10) unsigned', false, $column === 'id' ? null : '0', $column === 'id' ? 'auto_increment' : ''),
                $columns,
            );
        }
        $schema['rrdcheck'] = [new ColumnDefinition('local_data_id', 'mediumint(8) unsigned', false, null, '')];

        return $schema;
    }

    private static function narrow(string $name, ?string $default = '0'): ColumnDefinition
    {
        return new ColumnDefinition($name, 'mediumint(8) unsigned', false, $default, '');
    }

    /** @param array<string, list<ColumnDefinition>> $schema */
    private function widening(array $schema, bool $ok = true, DatabaseTarget $expected = DatabaseTarget::Local): ColumnWidening&MockObject
    {
        $widening = $this->createMock(ColumnWidening::class);
        // One read of the schema per run: the decisions all come from it.
        $widening->expects(self::once())->method('catalog')->willReturnCallback(static function (DatabaseTarget $target) use ($schema, $expected): ColumnCatalog {
            self::assertSame($expected, $target);

            return new ColumnCatalog($schema);
        });
        $name = static fn(string $table, array $changes): string => $table . ':' . implode(',', array_map(static fn(ColumnDefinition $c): string => $c->name, $changes));
        $widening->method('statement')->willReturnCallback(static fn(DatabaseTarget $target, string $table, array $changes): string => $name($table, $changes));
        $widening->method('widen')->willReturnCallback(function (DatabaseTarget $target, string $table, array $changes) use ($name, $ok, $expected): bool {
            self::assertSame($expected, $target);
            $this->sent[] = $name($table, $changes);

            return $ok;
        });

        return $widening;
    }

    private function widen(ColumnWidening $widening, bool $collector = false, bool $upgrade = true): WidenIdColumns
    {
        $operator = $this->createStub(ConsoleOperator::class);
        $operator->method('actor')->willReturn(new Actor(1, 'admin'));
        $operator->method('canUpgradeInstallation')->willReturn($upgrade);
        $maintenance = $this->createStub(DatabaseMaintenance::class);
        $maintenance->method('isRemoteCollector')->willReturn($collector);
        $trail = $this->createStub(AuditTrail::class);
        $trail->method('record')->willReturnCallback(function (AuditEvent $event): void {
            $this->events[] = $event;
        });

        return new WidenIdColumns(new MaintenanceTarget($operator, $maintenance), $widening, new SchemaChangeAudit($trail));
    }

    /**
     * @param list<array{table: string, column: ?string, event: WideningEvent, statement: ?string}> $steps
     * @return list<string>
     */
    private static function trace(array $steps): array
    {
        return array_map(static fn(array $s): string => $s['table'] . '.' . ($s['column'] ?? '*') . '=' . $s['event']->value, $steps);
    }

    public function testAFreshSchemaNeedsNoStatement(): void
    {
        $schema = self::freshSchema();
        $report = $this->widen($this->widening($schema))(false, null, true);
        self::assertSame([], $this->sent);
        self::assertSame([0, 0], [$report->tables(), $report->failed()]);
        self::assertNotSame([], $report->steps);
        self::assertSame([], array_filter($report->steps, static fn(array $s): bool => $s['event'] !== WideningEvent::AlreadyWide));
        // rrdcheck's mediumint is only checked once a named local_data_id needed widening.
        self::assertSame([], array_filter($report->steps, static fn(array $s): bool => $s['table'] === 'rrdcheck'));
        self::assertSame([], $this->events);
    }

    public function testANarrowNamedColumnIsWidenedAndJoinsTheSharedNames(): void
    {
        $schema = self::freshSchema();
        $schema['poller_output'] = [self::narrow('local_data_id')];
        $report = $this->widen($this->widening($schema))(false, null, true);
        // rrdcheck gets its own statement, as install/upgrades/1_2_17.php does.
        self::assertSame(['poller_output:local_data_id', 'rrdcheck:local_data_id'], $this->sent);
        self::assertSame(2, $report->tables());
        self::assertSame(
            [['database.widen-id-columns', 'database-table', 'local:poller_output', AuditEvent::ALLOWED, AuditEvent::SUCCEEDED],
                ['database.widen-id-columns', 'database-table', 'local:rrdcheck', AuditEvent::ALLOWED, AuditEvent::SUCCEEDED]],
            array_map(static fn(AuditEvent $e): array => [$e->action, $e->targetType, $e->targetId, $e->decision, $e->outcome], $this->events),
        );
        self::assertSame($this->events[0]->correlationId, $this->events[1]->correlationId);
    }

    public function testEachOtherTableGetsOneStatementForAllItsColumns(): void
    {
        $schema = self::freshSchema();
        $schema['a'] = [self::narrow('graph_id'), new ColumnDefinition('data_id', 'int(11)', true, '5', ''), new ColumnDefinition('name', 'varchar(9)', false, '', '')];
        $schema['b'] = [new ColumnDefinition('graph_id', 'bigint(20) unsigned', false, '0', '')];
        $report = $this->widen($this->widening($schema))(false, null, true);
        self::assertSame(['a:graph_id,data_id'], $this->sent);
        self::assertSame(1, $report->tables());
        $b = array_values(array_filter($report->steps, static fn(array $s): bool => $s['table'] === 'b'));
        self::assertSame([['table' => 'b', 'column' => 'graph_id', 'event' => WideningEvent::AlreadyWide, 'statement' => null]], $b);
    }

    public function testAnOtherTableReportsItsStatementWhereTheOriginalFirstPrintedIt(): void
    {
        $schema = self::freshSchema();
        // The original printed each column as it met it; the statement line
        // takes the place of the first column that needed widening.
        $schema['a'] = [new ColumnDefinition('data_id', 'int(10) unsigned', false, '0', ''), self::narrow('graph_id')];
        $schema['b'] = [self::narrow('graph_id'), new ColumnDefinition('data_id', 'int(10) unsigned', false, '0', '')];
        $report = $this->widen($this->widening($schema))(false, null, true);
        $other = array_values(array_filter($report->steps, static fn(array $s): bool => in_array($s['table'], ['a', 'b'], true)));
        self::assertSame(['a.data_id=already_wide', 'a.*=widened', 'b.*=widened', 'b.data_id=already_wide'], self::trace($other));
    }

    public function testNamedTablesReportTheirStatementAfterTheirColumns(): void
    {
        $schema = self::freshSchema();
        $schema['data_template_data'][0] = new ColumnDefinition('id', 'mediumint(8) unsigned', false, null, 'auto_increment');
        $report = $this->widen($this->widening($schema))(false, null, true);
        $named = array_values(array_filter($report->steps, static fn(array $s): bool => $s['table'] === 'data_template_data'));
        self::assertSame(['data_template_data.local_data_template_data_id=already_wide', 'data_template_data.local_data_id=already_wide', 'data_template_data.*=widened'], self::trace($named));
        self::assertSame(['data_template_data:id'], $this->sent);
    }

    public function testIdAndAutoIncrementColumnsDoNotJoinTheSharedNames(): void
    {
        $schema = self::freshSchema();
        $schema['graph_local'] = [new ColumnDefinition('id', 'mediumint(8) unsigned', false, null, 'auto_increment')];
        $schema['graph_tree_items'] = [new ColumnDefinition('local_graph_id', 'mediumint(8) unsigned', false, null, 'AUTO_INCREMENT')];
        $schema['data_local'] = [self::narrow('id', null)];
        $schema['x'] = [new ColumnDefinition('id', 'mediumint(8) unsigned', false, null, 'auto_increment'), self::narrow('local_graph_id')];
        $this->widen($this->widening($schema))(false, null, true);
        self::assertSame(['graph_local:id', 'data_local:id', 'graph_tree_items:local_graph_id'], $this->sent);
    }

    public function testGeneratedAndInvisibleColumnsAreSkipped(): void
    {
        $schema = self::freshSchema();
        $schema['poller_output'] = [new ColumnDefinition('local_data_id', 'mediumint(8) unsigned', false, null, 'VIRTUAL GENERATED')];
        $schema['a'] = [new ColumnDefinition('graph_id', 'mediumint(8) unsigned', false, '0', 'INVISIBLE'), new ColumnDefinition('data_id', 'int(10) unsigned', false, '0', 'INVISIBLE')];
        $report = $this->widen($this->widening($schema))(false, null, true);
        self::assertSame([], $this->sent);
        // A skipped named column does not make rrdcheck's local_data_id a shared name.
        $touched = array_values(array_filter($report->steps, static fn(array $s): bool => in_array($s['table'], ['poller_output', 'a', 'rrdcheck'], true)));
        self::assertSame(['poller_output.local_data_id=skipped', 'a.graph_id=skipped', 'a.data_id=already_wide'], self::trace($touched));
    }

    public function testNamedColumnsMatchByExactName(): void
    {
        $schema = self::freshSchema();
        // The original's LIKE also matched these; only the exact name counts now.
        $schema['poller_output'] = [self::narrow('LOCAL_DATA_ID'), self::narrow('localXdata_id')];
        $schema['Poller_Item'] = [self::narrow('local_data_id')];
        $report = $this->widen($this->widening($schema))(false, null, true);
        self::assertSame([], $this->sent);
        self::assertSame(['poller_output.local_data_id=missing_column'], self::trace(array_values(array_filter($report->steps, static fn(array $s): bool => $s['table'] === 'poller_output'))));
    }

    public function testAMissingNamedTableReportsEachColumnInOrder(): void
    {
        $schema = self::freshSchema();
        unset($schema['data_template_data']);
        $report = $this->widen($this->widening($schema))(false, null, true);
        $missing = array_values(array_filter($report->steps, static fn(array $s): bool => $s['event'] === WideningEvent::MissingColumn));
        self::assertSame(['id', 'local_data_template_data_id', 'local_data_id'], array_column($missing, 'column'));
        self::assertSame([], $this->events);
    }

    public function testAFailedStatementIsCountedAuditedAndTheRunContinues(): void
    {
        $schema = self::freshSchema();
        $schema['poller_output'] = [self::narrow('local_data_id')];
        $report = $this->widen($this->widening($schema, false))(false, null, true);
        self::assertSame(['poller_output:local_data_id', 'rrdcheck:local_data_id'], $this->sent);
        self::assertSame([2, 2], [$report->tables(), $report->failed()]);
        self::assertSame([AuditEvent::FAILED, AuditEvent::FAILED], array_map(static fn(AuditEvent $e): string => $e->outcome, $this->events));
    }

    public function testAHostileTableNameReachesThePortVerbatimAndIsAuditedByHash(): void
    {
        $hostile = 'a`; DROP TABLE host; --';
        $schema = self::freshSchema();
        $schema[$hostile] = [self::narrow('graph_id')];
        $this->widen($this->widening($schema))(false, null, true);
        self::assertSame([$hostile . ':graph_id'], $this->sent);
        self::assertSame(['database-table-sha256', 'local:' . hash('sha256', $hostile), AuditEvent::SUCCEEDED], [$this->events[0]->targetType, $this->events[0]->targetId, $this->events[0]->outcome]);
    }

    public function testADryRunPlansAndRunsNothing(): void
    {
        $schema = self::freshSchema();
        $schema['poller_output'] = [self::narrow('local_data_id')];
        $report = $this->widen($this->widening($schema))(false, null, false);
        self::assertSame([], $this->sent);
        self::assertTrue($report->dryRun);
        $planned = array_values(array_filter($report->steps, static fn(array $s): bool => $s['event'] === WideningEvent::Planned));
        self::assertSame(['poller_output:local_data_id', 'rrdcheck:local_data_id'], array_column($planned, 'statement'));
        self::assertSame([2, 0], [$report->tables(), $report->failed()]);
        self::assertSame([], $this->events);
    }

    public function testACollectorWidensTheMainDatabase(): void
    {
        $schema = self::freshSchema();
        $schema['poller_output'] = [self::narrow('local_data_id')];
        $report = $this->widen($this->widening($schema, true, DatabaseTarget::Main), true)(false, null, true);
        self::assertTrue($report->main);
        self::assertSame('main:poller_output', $this->events[0]->targetId);
    }

    public function testACollectorWithLocalWidensItsOwnDatabase(): void
    {
        $report = $this->widen($this->widening(self::freshSchema()), true)(true, null, true);
        self::assertFalse($report->main);
    }

    public function testAnOperatorWithoutTheUpgradeRealmIsRefused(): void
    {
        $widening = $this->createMock(ColumnWidening::class);
        $widening->expects(self::never())->method(self::anything());
        $this->expectException(InstallationAccessDenied::class);
        try {
            $this->widen($widening, false, false)(false, null, true);
        } finally {
            self::assertSame([['database.widen-id-columns', AuditEvent::DENIED, AuditEvent::DENIED]], array_map(static fn(AuditEvent $e): array => [$e->action, $e->decision, $e->outcome], $this->events));
        }
    }

    public function testARefusedDryRunIsAuditedAsADryRun(): void
    {
        $widening = $this->createMock(ColumnWidening::class);
        $widening->expects(self::never())->method(self::anything());
        try {
            $this->widen($widening, false, false)(false, 'ops', false);
            self::fail('A dry run passes the same realm check.');
        } catch (InstallationAccessDenied) {
        }
        self::assertSame(['database.widen-id-columns.dry-run'], array_map(static fn(AuditEvent $e): string => $e->action, $this->events));
    }
}
