<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Application\Command\ConvertTables;
use Kadupul\Platform\Application\Command\InstallationAccessDenied;
use Kadupul\Platform\Application\Command\SchemaChangeAudit;
use Kadupul\Platform\Application\Command\TableConversionStep;
use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Application\Port\TableCatalog;
use Kadupul\Platform\Application\Port\TableConversion;
use Kadupul\Platform\Application\ReadModel\ConversionOutcome;
use Kadupul\Platform\Application\ReadModel\TableOutcome;
use Kadupul\Platform\Application\ReadModel\TableResult;
use Kadupul\Platform\Domain\Schema\ConversionFlag;
use Kadupul\Platform\Domain\Schema\ConversionOptions;
use Kadupul\Platform\Domain\Schema\TableChange;
use Kadupul\Platform\Domain\Schema\TableCharset;
use Kadupul\Platform\Domain\Schema\TableStatus;
use Kadupul\Platform\Infrastructure\Doctrine\MainDatabaseNotConfigured;
use Kadupul\Tests\Fixtures\MaintenanceOperator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ConvertTablesTest extends TestCase
{
    use MaintenanceOperator;

    private function convert(TableConversion $conversion, bool $collector = false, bool $upgrade = true, ?SchemaChangeAudit $audit = null): ConvertTables
    {
        return new ConvertTables($this->maintenanceTarget($collector, $upgrade), $conversion, new TableConversionStep($conversion), $audit ?? $this->recordingAudit());
    }

    /**
     * @param list<ConversionFlag> $flags
     * @param list<string> $skip
     */
    private static function options(array $flags, ?string $table = null, array $skip = []): ConversionOptions
    {
        return new ConversionOptions($flags, $table, $skip, '1000000');
    }

    private static function myisam(): TableStatus
    {
        return new TableStatus('MyISAM', 'latin1_swedish_ci', 'Fixed', 2);
    }

    /** A conversion port whose InnoDB checks pass; tests add expectations. */
    private function conversion(): TableConversion&MockObject
    {
        $conversion = $this->createMock(TableConversion::class);
        $conversion->method('innodbEnabled')->willReturn(true);
        $conversion->method('filePerTable')->willReturn(true);

        return $conversion;
    }

    public function testTheInstallerCallConvertsTheNamedTableAndAuditsIt(): void
    {
        $conversion = $this->conversion();
        $conversion->method('tableStatuses')->with(DatabaseTarget::Local)->willReturn(new TableCatalog(['host' => self::myisam()]));
        $conversion->expects(self::never())->method('baseTables');
        $change = new TableChange(true, TableCharset::Utf8mb4, true);
        $conversion->method('statement')->with(DatabaseTarget::Local, 'host', $change)->willReturn('ALTER TABLE `host` x');
        $conversion->expects(self::once())->method('convert')->with(DatabaseTarget::Local, 'host', $change)->willReturn(true);
        $conversion->expects(self::never())->method('recordFailure');

        $report = $this->convert($conversion)(self::options([ConversionFlag::Utf8, ConversionFlag::Innodb, ConversionFlag::Dynamic], 'host'), false, null, true);

        self::assertSame(ConversionOutcome::Completed, $report->outcome);
        self::assertSame([['name' => 'host', 'result' => TableResult::Converted, 'rows' => 2, 'statement' => 'ALTER TABLE `host` x']], $report->tables);
        self::assertSame([['database.convert-tables', 'database-table', 'local:host', AuditEvent::ALLOWED, AuditEvent::SUCCEEDED, 1]], array_map(
            static fn(AuditEvent $e): array => [$e->action, $e->targetType, $e->targetId, $e->decision, $e->outcome, $e->actorId],
            $this->events,
        ));
    }

    public function testAnUnlistedTableIsReportedFailedWithoutAStatement(): void
    {
        $conversion = $this->conversion();
        $conversion->method('tableStatuses')->willReturn(new TableCatalog(['host' => self::myisam()]));
        $conversion->expects(self::never())->method('statement');
        $conversion->expects(self::never())->method('convert');
        $conversion->expects(self::once())->method('recordFailure')->with(
            DatabaseTarget::Local,
            "FATAL: Conversion of Table 'HOST`; DROP TABLE x' Failed.  Command: 'ALTER TABLE `HOST`; DROP TABLE x`  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'",
        );

        $report = $this->convert($conversion)(self::options([ConversionFlag::Utf8], 'HOST`; DROP TABLE x'), false, null, true);

        self::assertSame([['name' => 'HOST`; DROP TABLE x', 'result' => TableResult::Failed, 'rows' => null, 'statement' => null]], $report->tables);
        self::assertSame([['database-table-sha256', 'local:' . hash('sha256', 'HOST`; DROP TABLE x'), AuditEvent::ALLOWED, AuditEvent::FAILED]], array_map(
            static fn(AuditEvent $e): array => [$e->targetType, $e->targetId, $e->decision, $e->outcome],
            $this->events,
        ));
    }

    public function testAFailedStatementIsLoggedAuditedAndTheRunContinues(): void
    {
        $conversion = $this->conversion();
        $conversion->method('baseTables')->willReturn(['a', 'b', 'c']);
        $conversion->method('tableStatuses')->willReturn(new TableCatalog(['a' => self::myisam(), 'b' => self::myisam(), 'c' => new TableStatus('InnoDB', 'utf8mb4_unicode_ci', 'Dynamic', 9)]));
        $conversion->method('statement')->willReturnCallback(static fn(DatabaseTarget $target, string $table): string => 'ALTER TABLE ' . $table);
        $conversion->method('convert')->willReturnCallback(static fn(DatabaseTarget $target, string $table): bool => $table !== 'a');
        $conversion->expects(self::once())->method('recordFailure')->with(DatabaseTarget::Local, "FATAL: Conversion of Table 'a' Failed.  Command: 'ALTER TABLE `a`  ENGINE=Innodb'");

        $report = $this->convert($conversion)(self::options([ConversionFlag::Innodb]), false, null, true);

        self::assertSame([TableResult::Failed, TableResult::Converted, TableResult::Skipped], array_column($report->tables, 'result'));
        self::assertSame(1, $report->failed());
        self::assertSame([AuditEvent::FAILED, AuditEvent::SUCCEEDED], array_map(static fn(AuditEvent $e): string => $e->outcome, $this->events));
        self::assertSame($this->events[0]->correlationId, $this->events[1]->correlationId);
    }

    /**
     * The report walks baseTables(), never the catalog's own key order: a
     * catalog built with its rows in a different order than baseTables()
     * must still produce the same, baseTables()-ordered report. This pins
     * that MaintenanceConnections::tableCatalog()'s ORDER BY (added for audit
     * parity with the original's SHOW TABLES walk) cannot reorder convert's
     * output, because status() and has() are name lookups, not a walk.
     */
    public function testTheReportOrderFollowsBaseTablesNotTheCatalogsKeyOrder(): void
    {
        $conversion = $this->conversion();
        $conversion->method('baseTables')->willReturn(['a', 'b', 'c']);
        $conversion->method('tableStatuses')->willReturn(new TableCatalog(['c' => self::myisam(), 'a' => self::myisam(), 'b' => self::myisam()]));
        $conversion->method('statement')->willReturnCallback(static fn(DatabaseTarget $target, string $table): string => 'ALTER TABLE ' . $table);
        $conversion->method('convert')->willReturn(true);

        $report = $this->convert($conversion)(self::options([ConversionFlag::Innodb]), false, null, true);

        self::assertSame(['a', 'b', 'c'], array_column($report->tables, 'name'));
    }

    public function testAnAuditFailureDoesNotReplaceTheResult(): void
    {
        $trail = $this->createStub(AuditTrail::class);
        $trail->method('record')->willThrowException(new \RuntimeException('Audit sink is unavailable.'));
        $conversion = $this->conversion();
        $conversion->method('tableStatuses')->willReturn(new TableCatalog(['host' => self::myisam()]));
        $conversion->method('statement')->willReturn('ALTER TABLE `host` ENGINE=InnoDB');
        $conversion->method('convert')->willReturn(true);

        $report = $this->convert($conversion, audit: new SchemaChangeAudit($trail))(self::options([ConversionFlag::Innodb], 'host'), false, null, true);

        self::assertSame(TableResult::Converted, $report->tables[0]['result']);
    }

    public function testAnUnknownSkipTableStopsBeforeAnyTable(): void
    {
        $conversion = $this->createMock(TableConversion::class);
        $conversion->method('tableStatuses')->willReturn(new TableCatalog(['host' => self::myisam()]));
        $conversion->expects(self::never())->method('innodbEnabled');
        $conversion->expects(self::never())->method('convert');

        $report = $this->convert($conversion)(self::options([ConversionFlag::Innodb], null, ['host', 'hosts']), false, null, true);

        self::assertSame([ConversionOutcome::SkipTableMissing, 'hosts'], [$report->outcome, $report->missingSkipTable]);
    }

    public function testDisabledInnodbStopsBeforeAnyTable(): void
    {
        $conversion = $this->createMock(TableConversion::class);
        $conversion->method('tableStatuses')->willReturn(new TableCatalog(['host' => self::myisam()]));
        $conversion->method('innodbEnabled')->willReturn(false);
        $conversion->expects(self::never())->method('filePerTable');
        $conversion->expects(self::never())->method('convert');

        self::assertSame(ConversionOutcome::InnodbDisabled, $this->convert($conversion)(self::options([ConversionFlag::Innodb]), false, null, true)->outcome);
    }

    public function testFilePerTableOffStopsBeforeAnyTable(): void
    {
        $conversion = $this->createMock(TableConversion::class);
        $conversion->method('tableStatuses')->willReturn(new TableCatalog(['host' => self::myisam()]));
        $conversion->method('innodbEnabled')->willReturn(true);
        $conversion->method('filePerTable')->willReturn(false);
        $conversion->expects(self::never())->method('convert');

        self::assertSame(ConversionOutcome::FilePerTableDisabled, $this->convert($conversion)(self::options([ConversionFlag::Innodb]), false, null, true)->outcome);
    }

    public function testInnodbChecksRunOnlyForInnodbConversions(): void
    {
        $conversion = $this->createMock(TableConversion::class);
        $conversion->method('tableStatuses')->willReturn(new TableCatalog([]));
        $conversion->method('baseTables')->willReturn([]);
        $conversion->expects(self::never())->method('innodbEnabled');
        $conversion->expects(self::never())->method('filePerTable');

        self::assertSame(ConversionOutcome::Completed, $this->convert($conversion)(self::options([ConversionFlag::Utf8]), false, null, true)->outcome);
    }

    public function testADryRunPlansEveryStatementAndRunsNone(): void
    {
        $conversion = $this->conversion();
        $conversion->method('baseTables')->willReturn(['a', 'gone']);
        $conversion->method('tableStatuses')->willReturn(new TableCatalog(['a' => self::myisam()]));
        $conversion->method('statement')->willReturn('ALTER TABLE `a` x');
        $conversion->expects(self::never())->method('convert');
        $conversion->expects(self::never())->method('recordFailure');

        $report = $this->convert($conversion)(self::options([ConversionFlag::Innodb, ConversionFlag::Utf8]), false, null, false);

        self::assertTrue($report->dryRun);
        self::assertSame([
            ['name' => 'a', 'result' => TableResult::Planned, 'rows' => 2, 'statement' => 'ALTER TABLE `a` x'],
            ['name' => 'gone', 'result' => TableResult::Failed, 'rows' => null, 'statement' => null],
        ], $report->tables);
        self::assertSame([], $this->events);
    }

    #[DataProvider('targets')]
    public function testTheTargetFollowsTheCollectorRule(bool $collector, bool $local, DatabaseTarget $expected): void
    {
        $conversion = $this->createMock(TableConversion::class);
        $conversion->expects(self::once())->method('tableStatuses')->with($expected)->willReturn(new TableCatalog([]));
        $conversion->method('baseTables')->willReturn([]);

        $report = $this->convert($conversion, $collector)(self::options([ConversionFlag::Utf8]), $local, null, true);

        self::assertSame($expected === DatabaseTarget::Main, $report->main);
    }

    /** @return iterable<string, array{bool, bool, DatabaseTarget}> */
    public static function targets(): iterable
    {
        yield 'primary' => [false, false, DatabaseTarget::Local];
        yield 'collector' => [true, false, DatabaseTarget::Main];
        yield 'collector with --local' => [true, true, DatabaseTarget::Local];
    }

    public function testAMissingMainDatabasePropagates(): void
    {
        $conversion = $this->createStub(TableConversion::class);
        $conversion->method('tableStatuses')->willThrowException(new MainDatabaseNotConfigured());
        $this->expectException(MainDatabaseNotConfigured::class);
        $this->convert($conversion, true)(self::options([ConversionFlag::Innodb]), false, null, true);
    }

    public function testAnOperatorWithoutTheUpgradeRealmIsRefusedAndAudited(): void
    {
        $conversion = $this->createMock(TableConversion::class);
        $conversion->expects(self::never())->method(self::anything());
        try {
            $this->convert($conversion, true, false)(self::options([ConversionFlag::Innodb]), false, 'ops', true);
            self::fail('Expected a refusal');
        } catch (InstallationAccessDenied $denied) {
            self::assertSame([1, DatabaseTarget::Main], [$denied->actorId, $denied->target]);
        }
        self::assertSame([[AuditEvent::DENIED, AuditEvent::DENIED, 'database', 'main', 1]], array_map(
            static fn(AuditEvent $e): array => [$e->decision, $e->outcome, $e->targetType, $e->targetId, $e->actorId],
            $this->events,
        ));
    }

    public function testADryRunIsRefusedTheSameWayAndTheDenialSaysSo(): void
    {
        $conversion = $this->createMock(TableConversion::class);
        $conversion->expects(self::never())->method(self::anything());
        try {
            $this->convert($conversion, false, false)(self::options([ConversionFlag::Utf8]), false, null, false);
            self::fail('Expected a refusal');
        } catch (InstallationAccessDenied $denied) {
            self::assertSame(DatabaseTarget::Local, $denied->target);
        }
        self::assertSame([['database.convert-tables.dry-run', AuditEvent::DENIED, 'database', 'local']], array_map(
            static fn(AuditEvent $e): array => [$e->action, $e->decision, $e->targetType, $e->targetId],
            $this->events,
        ));
    }

    public function testStatementEventsNameTheMainDatabaseOnACollector(): void
    {
        $conversion = $this->conversion();
        $conversion->method('tableStatuses')->with(DatabaseTarget::Main)->willReturn(new TableCatalog(['host' => self::myisam()]));
        $conversion->method('statement')->willReturn('ALTER TABLE `host` ENGINE=InnoDB');
        $conversion->method('convert')->willReturn(true);

        $this->convert($conversion, true)(self::options([ConversionFlag::Innodb], 'host'), false, null, true);

        self::assertSame([['database.convert-tables', 'main:host']], array_map(static fn(AuditEvent $e): array => [$e->action, $e->targetId], $this->events));
    }

    /**
     * Only an exact key of the target's own catalog reaches DDL. These names
     * differ from a listed table by letter case, or carry a backtick, a
     * semicolon or a space, and none is listed.
     */
    #[DataProvider('hostileNames')]
    public function testAnUnlistedHostileNameNeverReachesAStatement(string $name, string $type, string $id): void
    {
        $conversion = $this->conversion();
        $conversion->method('tableStatuses')->willReturn(new TableCatalog(['host' => self::myisam()]));
        $conversion->expects(self::never())->method('statement');
        $conversion->expects(self::never())->method('convert');

        $report = $this->convert($conversion)(self::options([ConversionFlag::Innodb, ConversionFlag::Utf8], $name), false, null, true);

        self::assertSame([TableResult::Failed], array_column($report->tables, 'result'));
        // The refused attempt is still audited once, as failed.
        self::assertSame([[$type, $id, AuditEvent::FAILED]], array_map(static fn(AuditEvent $e): array => [$e->targetType, $e->targetId, $e->outcome], $this->events));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function hostileNames(): iterable
    {
        yield 'other case' => ['Host', 'database-table', 'local:Host'];
        yield 'backtick' => ['host`', 'database-table-sha256', 'local:' . hash('sha256', 'host`')];
        yield 'statement break' => ['host; DROP TABLE settings', 'database-table-sha256', 'local:' . hash('sha256', 'host; DROP TABLE settings')];
        yield 'trailing space' => ['host ', 'database-table-sha256', 'local:' . hash('sha256', 'host ')];
    }

    /** Names the audit id cannot carry are recorded by hash, one event each. */
    #[DataProvider('unusualNames')]
    public function testAListedUnusualNameIsAuditedByItsHash(string $name): void
    {
        $conversion = $this->conversion();
        $conversion->method('tableStatuses')->willReturn(new TableCatalog([$name => self::myisam()]));
        $conversion->method('statement')->willReturn('ALTER TABLE x');
        $conversion->expects(self::once())->method('convert')->with(DatabaseTarget::Local, $name, new TableChange(false, null, true))->willReturn(true);

        $this->convert($conversion)(self::options([ConversionFlag::Innodb], $name), false, null, true);

        self::assertSame([['database-table-sha256', 'local:' . hash('sha256', $name), AuditEvent::SUCCEEDED]], array_map(
            static fn(AuditEvent $e): array => [$e->targetType, $e->targetId, $e->outcome],
            $this->events,
        ));
    }

    /** @return iterable<string, array{string}> */
    public static function unusualNames(): iterable
    {
        yield 'dollar' => ['a$b'];
        yield 'backtick' => ['we`ird'];
    }

    public function testAnEventTheAuditCannotExpressSurfaces(): void
    {
        $trail = $this->createMock(AuditTrail::class);
        $trail->expects(self::never())->method('record');
        $this->expectException(\InvalidArgumentException::class);
        (new SchemaChangeAudit($trail))->denied('Not An Action', new InstallationAccessDenied(1, DatabaseTarget::Local), false);
    }

    public function testAStoppedDryRunSaysItWasADryRun(): void
    {
        $conversion = $this->createMock(TableConversion::class);
        $conversion->method('tableStatuses')->willReturn(new TableCatalog(['host' => self::myisam()]));

        $report = $this->convert($conversion)(self::options([ConversionFlag::Innodb], null, ['hosts']), false, null, false);

        self::assertSame([ConversionOutcome::SkipTableMissing, true], [$report->outcome, $report->dryRun]);
    }

    public function testTheStepConvertsWithoutAnOperatorOrAnAudit(): void
    {
        $conversion = $this->conversion();
        $change = new TableChange(true, TableCharset::Utf8mb4, true);
        $conversion->method('statement')->with(DatabaseTarget::Local, 'host', $change)->willReturn('ALTER TABLE `host` x');
        $conversion->expects(self::once())->method('convert')->with(DatabaseTarget::Local, 'host', $change)->willReturn(true);

        $step = new TableConversionStep($conversion);
        $options = self::options([ConversionFlag::Utf8, ConversionFlag::Innodb, ConversionFlag::Dynamic], 'host');

        self::assertNull($step->blocker(DatabaseTarget::Local, $options));
        self::assertEquals(
            new TableOutcome('host', TableResult::Converted, 2, 'ALTER TABLE `host` x', true),
            $step(DatabaseTarget::Local, 'host', new TableCatalog(['host' => self::myisam()]), $options, true),
        );
    }

    public function testTheStepReportsWhatAnAppliedRunAttempted(): void
    {
        $conversion = $this->conversion();
        $conversion->method('statement')->willReturn('ALTER TABLE `a` x');
        $conversion->expects(self::once())->method('convert')->willReturn(false);
        // Once for the refused statement, once for the unlisted name.
        $conversion->expects(self::exactly(2))->method('recordFailure');
        $step = new TableConversionStep($conversion);
        $catalog = new TableCatalog(['a' => self::myisam()]);
        // Utf8, so an unlisted name has a change to make and reaches the catalog check.
        $options = self::options([ConversionFlag::Utf8]);

        self::assertEquals(new TableOutcome('a', TableResult::Failed, 2, 'ALTER TABLE `a` x', true), $step(DatabaseTarget::Local, 'a', $catalog, $options, true));
        self::assertFalse($step(DatabaseTarget::Local, 'a', $catalog, $options, false)->attempted);
        self::assertEquals(new TableOutcome('gone', TableResult::Failed, null, null, true), $step(DatabaseTarget::Local, 'gone', $catalog, $options, true));
        self::assertEquals(new TableOutcome('gone', TableResult::Failed, null, null, false), $step(DatabaseTarget::Local, 'gone', $catalog, $options, false));
    }

    public function testTheStepNamesWhatStopsInnodbWork(): void
    {
        $conversion = $this->createMock(TableConversion::class);
        $conversion->method('innodbEnabled')->willReturn(true);
        $conversion->method('filePerTable')->willReturn(false);
        $step = new TableConversionStep($conversion);

        self::assertSame(ConversionOutcome::FilePerTableDisabled, $step->blocker(DatabaseTarget::Main, self::options([ConversionFlag::Innodb])));
        self::assertNull($step->blocker(DatabaseTarget::Main, self::options([ConversionFlag::Utf8])));
    }
}
