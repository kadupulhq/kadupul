<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Domain\Schema\AuditBaseline;
use Kadupul\Platform\Domain\Schema\BaselineColumn;
use Kadupul\Platform\Domain\Schema\BaselineIndex;
use Kadupul\Platform\Domain\Schema\ColumnType;
use Kadupul\Platform\Domain\Schema\LiveTable;
use Kadupul\Platform\Domain\Schema\ModifyColumn;
use Kadupul\Platform\Domain\Schema\PluginSchemaChanges;
use Kadupul\Platform\Domain\Schema\RebuildIndex;
use Kadupul\Platform\Domain\Schema\TableAudit;
use Kadupul\Platform\Domain\Schema\TableStatus;
use Kadupul\Platform\Domain\Schema\UnbuildableClause;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditSchemaSafetyTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function signChanges(): iterable
    {
        yield 'negative signed values cannot become unsigned' => ['int(11)', 'int(10) unsigned'];
        yield 'unsigned upper range cannot become signed' => ['int(10) unsigned', 'int(11)'];
        yield 'zerofill is effectively unsigned' => ['int(11)', 'int(10) zerofill'];
        yield 'larger unsigned still cannot contain negatives' => ['int(11)', 'bigint(20) unsigned'];
    }

    #[DataProvider('signChanges')]
    public function testSignChangesRemainErrorsWithExplicitRefusalInsteadOfWidening(string $liveType, string $targetType): void
    {
        $table = self::table($liveType);
        $audit = TableAudit::of($table, new AuditBaseline([self::baseline($targetType)], []), PluginSchemaChanges::none(), true);
        self::assertSame(1, $audit->errors);
        self::assertSame(0, $audit->warnings);
        self::assertSame([], $audit->widened);
        self::assertStringContainsString('signedness', $audit->findings[0]);
        self::assertStringNotContainsString('widened locally', implode('\n', $audit->findings));
        self::assertInstanceOf(UnbuildableClause::class, $audit->clauses[0]);
        self::assertFalse($audit->alter($table->status)->buildable());
    }

    public function testAWidthIncreaseDoesNotMakeSignedToUnsignedConversionSafe(): void
    {
        self::assertTrue(ColumnType::parse('bigint unsigned')->narrows(ColumnType::parse('int')));
        self::assertFalse(ColumnType::parse('bigint')->narrows(ColumnType::parse('int unsigned')));
        self::assertFalse(ColumnType::parse('int(10) unsigned')->narrows(ColumnType::parse('int(11) unsigned')));
    }

    public function testOrdinaryWideningStillRetainsTheLargerLocalType(): void
    {
        $table = self::table('bigint unsigned');
        $audit = TableAudit::of($table, new AuditBaseline([self::baseline('int unsigned')], []), PluginSchemaChanges::none(), true);
        self::assertSame(0, $audit->errors);
        self::assertSame(1, $audit->warnings);
        self::assertCount(1, $audit->widened);
        self::assertSame([], $audit->clauses);
    }

    /** @return iterable<string, array{string}> */
    public static function localCollations(): iterable
    {
        yield 'ordinary database default' => ['utf8mb4_general_ci'];
        yield 'explicit binary column' => ['utf8mb4_bin'];
    }

    #[DataProvider('localCollations')]
    public function testLocalCollationStaysVisibleButDoesNotBlockMissingColumnRepair(string $collation): void
    {
        $table = self::table('varchar(20)', $collation);
        $baseline = new AuditBaseline([self::baseline('varchar(20)', 'utf8mb4_unicode_ci'), new BaselineColumn('t', 2, 'missing', 'int(10)', 'NO', '', '0', '')], []);
        $audit = TableAudit::of($table, $baseline, PluginSchemaChanges::none(), true);
        self::assertSame(1, $audit->errors);
        self::assertSame(1, $audit->warnings);
        self::assertStringContainsString('Collation', implode('\n', $audit->findings));
        self::assertStringContainsString($collation, implode('\n', $audit->findings));
        self::assertTrue($audit->alter($table->status)->buildable());
        self::assertCount(1, $audit->clauses);
    }

    public function testRepairOfAnotherAttributePreservesExplicitLocalCollation(): void
    {
        $table = self::table('varchar(20)', 'utf8mb4_bin', null);
        $audit = TableAudit::of($table, new AuditBaseline([self::baseline('varchar(20)', 'utf8mb4_unicode_ci')], []), PluginSchemaChanges::none(), true);
        self::assertSame(1, $audit->errors);
        self::assertSame(1, $audit->warnings);
        self::assertCount(1, $audit->clauses);
        self::assertInstanceOf(ModifyColumn::class, $audit->clauses[0]);
        self::assertSame('utf8mb4_bin', $audit->clauses[0]->spec->collation);
        self::assertTrue($audit->alter($table->status)->buildable());
    }

    public function testCollationOnlyDriftNeverSchedulesAConversion(): void
    {
        $table = self::table('varchar(20)', 'utf8mb4_bin');
        $audit = TableAudit::of($table, new AuditBaseline([self::baseline('varchar(20)', 'utf8mb4_unicode_ci')], []), PluginSchemaChanges::none(), true);
        self::assertSame(0, $audit->errors);
        self::assertSame(1, $audit->warnings);
        self::assertSame([], $audit->clauses);
        self::assertNull($audit->alter($table->status));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function hashEngines(): iterable
    {
        yield 'InnoDB cannot install HASH' => ['InnoDB', false];
        yield 'MyISAM conversion also cannot install HASH' => ['MyISAM', false];
        yield 'MEMORY supports HASH' => ['MEMORY', true];
    }

    #[DataProvider('hashEngines')]
    public function testRebuildRequiresAnAlgorithmSupportedByTheEffectiveEngine(string $engine, bool $buildable): void
    {
        $table = self::table('int(10) unsigned', null, '0', $engine);
        $index = ['Table' => 't', 'Non_unique' => '0', 'Key_name' => 'PRIMARY', 'Seq_in_index' => '1', 'Column_name' => 'x', 'Collation' => 'A', 'Cardinality' => '4', 'Sub_part' => null, 'Packed' => null, 'Null' => '', 'Index_type' => 'BTREE', 'Comment' => ''];
        $table = new LiveTable('t', $table->status, $table->columns, [$index]);
        $baseline = new AuditBaseline([self::baseline('int(10) unsigned')], [new BaselineIndex('t', 0, 'PRIMARY', 1, 'x', null, 0, null, null, '', 'HASH', '')]);
        $audit = TableAudit::of($table, $baseline, PluginSchemaChanges::none(), true);
        self::assertSame(1, $audit->errors);
        self::assertSame($buildable, $audit->alter($table->status)->buildable());
        self::assertInstanceOf($buildable ? RebuildIndex::class : UnbuildableClause::class, $audit->clauses[0]);
    }

    /** @return iterable<string, array{string, string, string, string, bool, string}> */
    public static function secondaryIndexDrift(): iterable
    {
        yield 'HASH live secondary becomes baseline BTREE' => ['HASH', 'A', '', '', true, 'Index_type'];
        yield 'descending baseline cannot lose its direction' => ['BTREE', 'D', '', '', false, 'Collation'];
        yield 'nullable metadata drift rebuilds only once' => ['BTREE', 'A', '', 'YES', true, 'Null'];
    }

    #[DataProvider('secondaryIndexDrift')]
    public function testSecondaryIndexMetadataDriftRetainsOneExplicitRepair(
        string $liveAlgorithm,
        string $baselineDirection,
        string $liveNull,
        string $baselineNull,
        bool $buildable,
        string $attribute,
    ): void {
        $table = self::table('int(10) unsigned');
        $index = ['Table' => 't', 'Non_unique' => '1', 'Key_name' => 'secondary', 'Seq_in_index' => '1', 'Column_name' => 'x', 'Collation' => 'A', 'Cardinality' => '999', 'Sub_part' => null, 'Packed' => null, 'Null' => $liveNull, 'Index_type' => $liveAlgorithm, 'Comment' => ''];
        $table = new LiveTable('t', $table->status, $table->columns, [$index]);
        $baseline = new AuditBaseline([self::baseline('int(10) unsigned')], [new BaselineIndex('t', 1, 'secondary', 1, 'x', $baselineDirection, 0, null, null, $baselineNull, 'BTREE', '')]);
        $audit = TableAudit::of($table, $baseline, PluginSchemaChanges::none(), true);

        self::assertSame(1, $audit->errors);
        self::assertCount(1, $audit->findings);
        self::assertStringContainsString("Attribute '" . $attribute . "' invalid", $audit->findings[0]);
        self::assertCount(1, $audit->clauses);
        self::assertSame($buildable, $audit->alter($table->status)->buildable());
        self::assertInstanceOf($buildable ? RebuildIndex::class : UnbuildableClause::class, $audit->clauses[0]);
        if ($buildable) {
            self::assertSame(['secondary'], $audit->clauses[0]->drops);
            self::assertSame("DROP INDEX `secondary`,\n   ADD INDEX `secondary` (`x`) USING BTREE", $audit->clauses[0]->legacy());
        }
    }

    public function testCardinalityAloneNeverSchedulesAnIndexRebuild(): void
    {
        $table = self::table('int(10) unsigned');
        $index = ['Table' => 't', 'Non_unique' => '1', 'Key_name' => 'secondary', 'Seq_in_index' => '1', 'Column_name' => 'x', 'Collation' => 'A', 'Cardinality' => '999', 'Sub_part' => null, 'Packed' => null, 'Null' => '', 'Index_type' => 'BTREE', 'Comment' => ''];
        $table = new LiveTable('t', $table->status, $table->columns, [$index]);
        $baseline = new AuditBaseline([self::baseline('int(10) unsigned')], [new BaselineIndex('t', 1, 'secondary', 1, 'x', 'A', 0, null, null, '', 'BTREE', '')]);
        $audit = TableAudit::of($table, $baseline, PluginSchemaChanges::none(), true);

        self::assertSame(0, $audit->errors);
        self::assertSame([], $audit->findings);
        self::assertSame([], $audit->clauses);
        self::assertNull($audit->alter($table->status));
    }

    private static function baseline(string $type, ?string $collation = null): BaselineColumn
    {
        return new BaselineColumn('t', 1, 'x', $type, 'NO', '', '0', '', $collation);
    }

    private static function table(string $type, ?string $collation = null, ?string $default = '0', string $engine = 'InnoDB'): LiveTable
    {
        return new LiveTable('t', new TableStatus($engine, 'utf8mb4_general_ci', 'Dynamic', 0), [
            ['Field' => 'x', 'Type' => $type, 'Collation' => $collation, 'Null' => 'NO', 'Key' => '', 'Default' => $default, 'Extra' => ''],
        ], []);
    }
}
