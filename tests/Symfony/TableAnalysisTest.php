<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Application\ReadModel\AnalysisOutcome;
use Kadupul\Platform\Application\ReadModel\AnalysisReport;
use Kadupul\Platform\Application\ReadModel\TableAnalysis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TableAnalysisTest extends TestCase
{
    #[DataProvider('outcomes')]
    public function testOutcomePreservesTheJsonContract(AnalysisOutcome $outcome, bool $ok): void
    {
        $table = new TableAnalysis('host', $outcome);
        self::assertSame($ok, $table->succeeded());
        self::assertSame(['name' => 'host', 'ok' => $ok], $table->toArray());
        self::assertSame('{"name":"host","ok":' . ($ok ? 'true' : 'false') . '}', json_encode($table->toArray(), JSON_THROW_ON_ERROR));
    }

    public static function outcomes(): iterable
    {
        yield 'success' => [AnalysisOutcome::Succeeded, true];
        yield 'failure' => [AnalysisOutcome::Failed, false];
    }

    #[DataProvider('propertyChanges')]
    public function testPropertiesCannotBeChanged(string $property, string|AnalysisOutcome $value): void
    {
        $table = new TableAnalysis('host', AnalysisOutcome::Succeeded);
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');
        $table->$property = $value;
    }

    public static function propertyChanges(): iterable
    {
        yield 'name' => ['name', 'settings'];
        yield 'outcome' => ['outcome', AnalysisOutcome::Failed];
    }

    public function testSerializationCannotMutateTheResult(): void
    {
        $table = new TableAnalysis('host', AnalysisOutcome::Succeeded);
        $serialized = $table->toArray();
        $serialized['name'] = 'settings';
        $serialized['ok'] = false;
        self::assertSame(['name' => 'host', 'ok' => true], $table->toArray());
    }

    public function testReportCountsFailuresAndKeepsTableOrder(): void
    {
        $tables = [new TableAnalysis('settings', AnalysisOutcome::Failed), new TableAnalysis('host', AnalysisOutcome::Succeeded), new TableAnalysis('poller', AnalysisOutcome::Failed)];
        $report = new AnalysisReport(false, false, $tables, 0);
        self::assertSame(2, $report->failed());
        self::assertSame($tables, $report->tables);
        self::assertSame(0, (new AnalysisReport(false, false, [], 0))->failed());
        self::assertSame(0, (new AnalysisReport(false, false, [$tables[1]], 0))->failed());
    }
}
