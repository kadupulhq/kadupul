<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Domain\Schema\AddColumn;
use Kadupul\Platform\Domain\Schema\AuditBaseline;
use Kadupul\Platform\Domain\Schema\AuditMode;
use Kadupul\Platform\Domain\Schema\AuditSchemaDump;
use Kadupul\Platform\Domain\Schema\AuditTableStatus;
use Kadupul\Platform\Domain\Schema\BaselineColumn;
use Kadupul\Platform\Domain\Schema\BaselineIndex;
use Kadupul\Platform\Domain\Schema\ColumnDrift;
use Kadupul\Platform\Domain\Schema\ColumnExtra;
use Kadupul\Platform\Domain\Schema\ColumnType;
use Kadupul\Platform\Domain\Schema\DefaultCharset;
use Kadupul\Platform\Domain\Schema\DropIndex;
use Kadupul\Platform\Domain\Schema\IndexDrift;
use Kadupul\Platform\Domain\Schema\InvalidAuditSchema;
use Kadupul\Platform\Domain\Schema\LiveTable;
use Kadupul\Platform\Domain\Schema\ModifyColumn;
use Kadupul\Platform\Domain\Schema\PluginSchemaChanges;
use Kadupul\Platform\Domain\Schema\RebuildIndex;
use Kadupul\Platform\Domain\Schema\TableAudit;
use Kadupul\Platform\Domain\Schema\TableStatus;
use Kadupul\Platform\Domain\Schema\UnbuildableClause;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditSchemaTest extends TestCase
{
    private const string UTF8 = 'utf8mb4_unicode_ci';

    /** @param array{0: ?string, 1: ?string, 2: ?string, 3: ?string, 4: ?string} $live Type, Null, Key, Default, Extra */
    private static function live(string $table, array $live, string $collation = self::UTF8, string $engine = 'InnoDB'): LiveTable
    {
        return new LiveTable($table, new TableStatus($engine, $collation, 'Dynamic', 0), [
            ['Field' => 'x', 'Type' => $live[0], 'Null' => $live[1], 'Key' => $live[2], 'Default' => $live[3], 'Extra' => $live[4]],
        ], []);
    }

    /** @param array{0: ?string, 1: ?string, 2: ?string, 3: ?string, 4: ?string} $row table_type, table_null, table_key, table_default, table_extra */
    private static function baseline(string $table, array $row): AuditBaseline
    {
        return new AuditBaseline([new BaselineColumn($table, 1, 'x', ...$row)], []);
    }

    /**
     * Every expectation was produced while planning by running the script's
     * own lines 478-527 and 705-755 against the same row pair with PHP 8.4.25.
     *
     * @return iterable<string, array{0: list<?string>, 1: list<?string>, 2: list<string>, 3: ?string}>
     */
    public static function originalColumnCases(): iterable
    {
        yield 'lost auto_increment' => [['int(10) unsigned', 'NO', 'PRI', null, ''], ['int(10) unsigned', 'NO', 'PRI', null, 'auto_increment'],
            ["ERROR Col: 'x', Attribute 'Extra' invalid. Should be: 'auto_increment', Is: '1'"], 'MODIFY COLUMN `x` int(10) unsigned NOT NULL auto_increment'];
        yield 'lost default 5' => [['int(10) unsigned', 'NO', '', null, ''], ['int(10) unsigned', 'NO', '', '5', ''],
            ["ERROR Col: 'x', Attribute 'Default' invalid. Should be: '5', Is: '1'"], "MODIFY COLUMN `x` int(10) unsigned NOT NULL DEFAULT '5'"];
        yield 'lost default 0 reads as clean' => [['int(10) unsigned', 'NO', '', null, ''], ['int(10) unsigned', 'NO', '', '0', ''], [], null];
        yield 'narrow type' => [['mediumint(8) unsigned', 'NO', '', '0', ''], ['int(10) unsigned', 'NO', '', '0', ''],
            ["ERROR Col: 'x', Attribute 'Type' invalid. Should be: 'int(10) unsigned', Is: 'mediumint(8) unsigned'"], "MODIFY COLUMN `x` int(10) unsigned NOT NULL DEFAULT '0'"];
        yield 'nullable drift' => [['varchar(20)', 'YES', '', '', ''], ['varchar(20)', 'NO', '', '', ''],
            ["ERROR Col: 'x', Attribute 'Null' invalid. Should be: 'NO', Is: 'YES'"], "MODIFY COLUMN `x` varchar(20) NOT NULL DEFAULT ''"];
        yield 'baseline EXTRA of 1 matches an empty one' => [['timestamp', 'NO', '', 'current_timestamp()', ''], ['timestamp', 'NO', '', 'current_timestamp()', '1'], [], null];
        yield 'key drift alters without a finding' => [['int(10) unsigned', 'NO', '', null, ''], ['int(10) unsigned', 'NO', 'MUL', '0', ''], [], "MODIFY COLUMN `x` int(10) unsigned NOT NULL DEFAULT '0'"];
        yield 'lost on update' => [['timestamp', 'YES', '', 'current_timestamp()', ''], ['timestamp', 'YES', '', 'current_timestamp()', 'on update current_timestamp()'],
            ["ERROR Col: 'x', Attribute 'Extra' invalid. Should be: 'on update CURRENT_TIMESTAMP', Is: '1'"], 'MODIFY COLUMN `x` timestamp DEFAULT CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'];
        yield 'string default' => [['char(2)', 'YES', '', '00', ''], ['char(2)', 'YES', '', 'FF', ''],
            ["ERROR Col: 'x', Attribute 'Default' invalid. Should be: 'FF', Is: '00'"], 'MODIFY COLUMN `x` char(2) DEFAULT "FF"'];
    }

    /**
     * @param list<?string> $live
     * @param list<?string> $baseline
     * @param list<string> $findings
     */
    #[DataProvider('originalColumnCases')]
    public function testColumnDriftMatchesTheOriginalExpressions(array $live, array $baseline, array $findings, ?string $legacy): void
    {
        $result = ColumnDrift::audit(self::live('t', $live), self::baseline('t', $baseline), PluginSchemaChanges::none(), true);

        self::assertSame($findings, $result['lines']);
        self::assertSame($legacy, isset($result['clauses'][0]) ? $result['clauses'][0]->legacy() : null);
        self::assertSame($legacy === null ? 0 : 1, $result['errors']);
    }

    public function testTheModifyCarriesTypedPartsFromTheRewrittenRow(): void
    {
        $clause = ColumnDrift::audit(self::live('t', ['timestamp', 'YES', '', 'current_timestamp()', '']), self::baseline('t', ['timestamp', 'YES', '', 'current_timestamp()', 'on update current_timestamp()']), PluginSchemaChanges::none(), false)['clauses'][0];

        self::assertInstanceOf(ModifyColumn::class, $clause);
        self::assertSame(['x', 'timestamp', false, null, true, ColumnExtra::OnUpdateNow], [$clause->spec->name, $clause->spec->type->sql(), $clause->spec->notNull, $clause->spec->default, $clause->spec->defaultNow, $clause->spec->extra]);
    }

    public function testAMissingColumnIsAddedAfterItsBaselinePredecessor(): void
    {
        $baseline = new AuditBaseline([
            new BaselineColumn('t', 1, 'x', 'int(10) unsigned', 'NO', 'PRI', null, 'auto_increment'),
            new BaselineColumn('t', 2, 'name', 'varchar(64)', 'NO', '', '', ''),
        ], []);
        $table = new LiveTable('t', new TableStatus('InnoDB', self::UTF8, 'Dynamic', 0), [['Field' => 'x', 'Type' => 'int(10) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => 'auto_increment']], []);

        $result = ColumnDrift::audit($table, $baseline, PluginSchemaChanges::none(), true);

        self::assertSame(["WARNING Col: 'name' is missing from 't'"], $result['lines']);
        self::assertInstanceOf(AddColumn::class, $result['clauses'][0]);
        self::assertSame(["ADD COLUMN `name` varchar(64) NOT NULL DEFAULT '' AFTER `x`", 'x'], [$result['clauses'][0]->legacy(), $result['clauses'][0]->after]);
    }

    public function testColumnsMatchLikeDbColumnExists(): void
    {
        // SHOW COLUMNS ... LIKE 'host_id' also matches "hostXid" and ignores case.
        $table = new LiveTable('t', new TableStatus('InnoDB', self::UTF8, 'Dynamic', 0), [['Field' => 'HOSTXID', 'Type' => 'int', 'Null' => 'NO', 'Key' => '', 'Default' => null, 'Extra' => '']], []);
        self::assertTrue($table->hasColumnLike('host_id'));
        self::assertFalse($table->hasColumnLike('host_idx'));
    }

    public function testTheShapeIgnoresRowCountsAndCardinalityOnly(): void
    {
        $index = static fn(string $cardinality): array => ['Table' => 't', 'Non_unique' => '1', 'Key_name' => 'a', 'Seq_in_index' => '1', 'Column_name' => 'a',
            'Collation' => 'A', 'Cardinality' => $cardinality, 'Sub_part' => null, 'Packed' => null, 'Null' => '', 'Index_type' => 'BTREE', 'Comment' => ''];
        $table = static fn(string $collation, ?string $default, int $rows, string $cardinality): LiveTable => new LiveTable(
            't',
            new TableStatus('InnoDB', $collation, 'Dynamic', $rows),
            [['Field' => 'a', 'Type' => 'int(10)', 'Null' => 'NO', 'Key' => 'MUL', 'Default' => $default, 'Extra' => '']],
            [$index($cardinality)]
        );
        $read = $table(self::UTF8, '0', 10, '10');

        self::assertTrue($read->sameShape($table(self::UTF8, '0', 99, '99')));
        self::assertFalse($read->sameShape($table(self::UTF8, '1', 10, '10')));
        self::assertFalse($read->sameShape($table('latin1_swedish_ci', '0', 10, '10')));
    }

    public function testAPluginColumnIsNotAWarning(): void
    {
        $table = new LiveTable('host', new TableStatus('InnoDB', self::UTF8, 'Dynamic', 0), [['Field' => 'thold_x', 'Type' => 'int', 'Null' => 'NO', 'Key' => '', 'Default' => null, 'Extra' => '']], []);
        $baseline = new AuditBaseline([new BaselineColumn('host', 1, 'id', 'int(10) unsigned', 'NO', 'PRI', null, 'auto_increment')], []);

        $plugin = ColumnDrift::audit($table, $baseline, new PluginSchemaChanges([], [['HOST', 'THOLD_X']]), true);
        $other = ColumnDrift::audit($table, $baseline, PluginSchemaChanges::none(), true);

        self::assertSame(0, $plugin['warnings']);
        self::assertSame(["WARNING Col: 'thold_x', does not exist in default Kadupul.  Plugin possible"], array_slice($other['lines'], 0, 1));
        self::assertSame(1, $other['warnings']);
    }

    public function testIndexesAreDroppedRebuiltAndAddedAsTheOriginalDid(): void
    {
        $index = static fn(string $key, int $seq, string $column, int $nonUnique = 1): BaselineIndex => new BaselineIndex('t', $nonUnique, $key, $seq, $column, 'A', 0, null, null, '', 'BTREE', '');
        $baseline = new AuditBaseline([new BaselineColumn('t', 1, 'a', 'int(10)', 'NO', '', '0', ''), new BaselineColumn('t', 2, 'b', 'int(10)', 'NO', '', '0', '')], [
            $index('ab', 1, 'a'), $index('ab', 2, 'b'), $index('b', 1, 'b'),
        ]);
        $live = static fn(string $key, string $seq, string $column): array => ['Table' => 't', 'Non_unique' => '1', 'Key_name' => $key, 'Seq_in_index' => $seq, 'Column_name' => $column,
            'Collation' => 'A', 'Cardinality' => '0', 'Sub_part' => null, 'Packed' => null, 'Null' => '', 'Index_type' => 'BTREE', 'Comment' => ''];
        $columns = [['Field' => 'a', 'Type' => 'int(10)', 'Null' => 'NO', 'Key' => '', 'Default' => '0', 'Extra' => ''], ['Field' => 'b', 'Type' => 'int(10)', 'Null' => 'NO', 'Key' => '', 'Default' => '0', 'Extra' => '']];
        $table = new LiveTable('t', new TableStatus('MyISAM', 'latin1_swedish_ci', 'Dynamic', 0), $columns, [$live('ab', '1', 'a'), $live('extra', '1', 'b')]);

        $audit = TableAudit::of($table, $baseline, PluginSchemaChanges::none(), true);

        self::assertSame([
            "WARNING Index: 'extra', does not exist in default Kadupul.  Dropping.",
            "WARNING Index: 'ab', has differing number of columns.  Dropping.",
            "ERROR Index: 'b', is missing from 't'",
        ], $audit->findings);
        self::assertSame([DropIndex::class, RebuildIndex::class, RebuildIndex::class], array_map(static fn(object $clause): string => $clause::class, $audit->clauses));
        self::assertSame(
            "ALTER TABLE `t`\n   DROP INDEX extra,\n   DROP INDEX `ab`,\n   ADD INDEX `ab` (`a`,`b`) USING BTREE,\n   ADD INDEX `b` (`b`) USING BTREE,\n   ENGINE=InnoDB ROW_FORMAT=Dynamic CHARSET=latin1;",
            $audit->alter($table->status)->legacy()
        );
    }

    public function testAnInexpressibleClauseMakesTheTableUnbuildable(): void
    {
        // No USING: the original never closed the column list, and the server refused it.
        $baseline = new AuditBaseline([new BaselineColumn('t', 1, 'a', 'int(10)', 'NO', '', '0', '')], [new BaselineIndex('t', 1, 'a', 1, 'a', 'A', 0, null, null, '', null, '')]);
        $table = new LiveTable('t', new TableStatus('InnoDB', self::UTF8, 'Dynamic', 0), [['Field' => 'a', 'Type' => 'int(10)', 'Null' => 'NO', 'Key' => '', 'Default' => '0', 'Extra' => '']], []);

        $audit = TableAudit::of($table, $baseline, PluginSchemaChanges::none(), false);
        $alter = $audit->alter($table->status);

        self::assertInstanceOf(UnbuildableClause::class, $audit->clauses[0]);
        self::assertSame('ADD INDEX `a` (`a`', $audit->clauses[0]->legacy());
        self::assertFalse($alter->buildable());
        // An unknown charset has no typed form either.
        self::assertFalse((new TableAudit('t', AuditTableStatus::Audited, [], 1, 0, [new DropIndex('x')]))->alter(new TableStatus('InnoDB', 'koi8r_general_ci', 'Dynamic', 0))->buildable());
        self::assertSame(DefaultCharset::Utf8mb4, (new TableAudit('t', AuditTableStatus::Audited, [], 1, 0, [new DropIndex('x')]))->alter(new TableStatus('InnoDB', null, null, null))->charset);
    }

    public function testATableOutsideTheBaselineIsUnknownOrAPlugin(): void
    {
        $table = self::live('thold_data', ['int', 'NO', '', null, '']);
        self::assertSame(AuditTableStatus::Unknown, TableAudit::of($table, AuditBaseline::empty(), PluginSchemaChanges::none(), true)->status);
        self::assertSame(AuditTableStatus::Plugin, TableAudit::of($table, AuditBaseline::empty(), new PluginSchemaChanges(['THOLD_DATA'], []), true)->status);
    }

    public function testTheShippedDumpParses(): void
    {
        $baseline = AuditSchemaDump::parse((string) file_get_contents(dirname(__DIR__, 2) . '/docs/audit_schema.sql'));

        self::assertCount(1019, $baseline->columnRows);
        self::assertCount(370, $baseline->indexRows);
        self::assertSame(['class', 'time'], array_map(static fn(BaselineIndex $index): string => $index->columnName, $baseline->index('user_auth_row_cache', 'class_time')));
        // mysqldump wrote each table's rows in primary key order under a case-insensitive collation.
        self::assertSame(['graph_template_id', 'PRIMARY', 'user_id'], array_values(array_unique(array_map(static fn(BaselineIndex $index): string => $index->keyName, $baseline->indexes('AGGREGATE_GRAPH_TEMPLATES')))));
    }

    /** @return iterable<string, array{string}> */
    public static function unusableDumps(): iterable
    {
        yield 'another table' => ["INSERT INTO `user_auth` VALUES (1,'admin');"];
        yield 'an extended insert' => ["INSERT INTO `table_columns` VALUES ('a',1,'b','int','NO','',NULL,''),('a',2,'c','int','NO','',NULL,'');"];
        yield 'a missing value' => ["INSERT INTO `table_columns` VALUES ('a',1,'b','int','NO','',NULL);"];
        yield 'a sequence that is text' => ["INSERT INTO `table_columns` VALUES ('a','1','b','int','NO','',NULL,'');"];
        yield 'a function call' => ["INSERT INTO `table_indexes` VALUES ('a',0,'PRIMARY',1,'id','A',SLEEP(1),NULL,NULL,'','BTREE','');"];
    }

    #[DataProvider('unusableDumps')]
    public function testAnyOtherInsertMakesTheDumpUnusable(string $line): void
    {
        try {
            AuditSchemaDump::parse("-- header\n" . $line . "\n");
            self::fail('Expected the dump to be refused');
        } catch (InvalidAuditSchema $invalid) {
            self::assertSame(2, $invalid->lineNumber);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function hostileDumps(): iterable
    {
        yield 'a statement after the row' => ["INSERT INTO `table_columns` VALUES ('t',1,'x','int(10)','NO','',NULL,'');DROP TABLE host;(1);"];
        yield 'a second row smuggled into the tuple' => ["INSERT INTO `table_columns` VALUES ('t',1,'x','int(10)','NO','',NULL,'')),(('t',2,'y','int','NO','',NULL,'');"];
        yield 'an escaped closing quote' => ["INSERT INTO `table_columns` VALUES ('t',1,'x\\','int(10)','NO','',NULL,'');"];
        yield 'a comment' => ["INSERT INTO `table_columns` VALUES ('t',1,'x','int(10)','NO','',NULL,'') /* x */;"];
        yield 'an unquoted word' => ["INSERT INTO `table_columns` VALUES ('t',1,'x',CHAR(96),'NO','',NULL,'');"];
    }

    #[DataProvider('hostileDumps')]
    public function testALineThatIsMoreThanOneRowOfLiteralsIsRefused(string $line): void
    {
        $this->expectException(InvalidAuditSchema::class);
        AuditSchemaDump::parse($line);
    }

    /** @return iterable<string, array{string}> */
    public static function otherWrites(): iterable
    {
        yield 'INSERT IGNORE' => ["INSERT IGNORE INTO `table_columns` VALUES ('t',1,'x','int(10)','NO','',NULL,'');"];
        yield 'REPLACE' => ["REPLACE INTO `table_columns` VALUES ('t',1,'x','int(10)','NO','',NULL,'');"];
        yield 'lowercase' => ["insert into `table_columns` VALUES ('t',1,'x','int(10)','NO','',NULL,'');"];
        yield 'indented' => ["  INSERT INTO `table_columns` VALUES ('t',1,'x','int(10)','NO','',NULL,'');"];
        yield 'a tab after INSERT' => ["INSERT\tINTO `table_columns` VALUES ('t',1,'x','int(10)','NO','',NULL,'');"];
        yield 'a versioned comment' => ["/*!40000 INSERT INTO `table_columns` VALUES ('t',1,'x','int(10)','NO','',NULL,'') */;"];
    }

    /** Rows written any other way than the one form parsed would otherwise be skipped without a word. */
    #[DataProvider('otherWrites')]
    public function testAnyOtherWayOfWritingRowsIsRefusedNotSkipped(string $line): void
    {
        try {
            AuditSchemaDump::parse("-- header\n/*!40000 ALTER TABLE `table_columns` DISABLE KEYS */;\n" . $line . "\n");
            self::fail('Expected the dump to be refused');
        } catch (InvalidAuditSchema $invalid) {
            self::assertSame(3, $invalid->lineNumber);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function backslashDefaults(): iterable
    {
        yield 'a trailing backslash' => ["'abc\\\\'", 'abc\\'];
        yield 'a backslash before the closing quote' => ["'abc\\\\\\''", "abc\\'"];
    }

    /** The literal the adapter quotes is the decoded value, whole, so its backslash cannot escape the closing quote. */
    #[DataProvider('backslashDefaults')]
    public function testADefaultEndingInABackslashStaysOneLiteral(string $dumped, string $default): void
    {
        $baseline = AuditSchemaDump::parse("INSERT INTO `table_columns` VALUES ('t',1,'a','varchar(20)','NO','',{$dumped},'');");
        $table = new LiveTable('t', new TableStatus('InnoDB', self::UTF8, 'Dynamic', 0), [['Field' => 'a', 'Type' => 'varchar(20)', 'Null' => 'NO', 'Key' => '', 'Default' => 'x', 'Extra' => '']], []);

        $clauses = TableAudit::of($table, $baseline, PluginSchemaChanges::none(), false)->clauses;

        self::assertCount(1, $clauses);
        self::assertInstanceOf(ModifyColumn::class, $clauses[0]);
        self::assertSame($default, $clauses[0]->spec->default);
        self::assertTrue($clauses[0]->spec->type->sql() === 'varchar(20)' && $clauses[0]->spec->notNull);
    }

    public function testAHostileIndexColumnMakesTheRebuildUnbuildable(): void
    {
        $baseline = AuditSchemaDump::parse("INSERT INTO `table_indexes` VALUES ('t',1,'k',1,'a`), DROP TABLE host, ADD INDEX z (`a','A',0,NULL,NULL,'','BTREE','');");

        $clauses = IndexDrift::audit(new LiveTable('t', new TableStatus('InnoDB', self::UTF8, 'Dynamic', 0), [], []), $baseline, false)['clauses'];

        self::assertCount(1, $clauses);
        self::assertInstanceOf(UnbuildableClause::class, $clauses[0]);
    }

    /** The added column's own name, type and default are fine; only the column it follows is not. */
    public function testAHostileAfterNameAloneMakesTheAddUnbuildable(): void
    {
        $baseline = AuditSchemaDump::parse(implode("\n", [
            "INSERT INTO `table_columns` VALUES ('t',1,'x` int, DROP TABLE host, ADD `y','int(10)','NO','','0','');",
            "INSERT INTO `table_columns` VALUES ('t',2,'name','varchar(64)','NO','','','');",
        ]));
        $table = new LiveTable('t', new TableStatus('InnoDB', self::UTF8, 'Dynamic', 0), [['Field' => 'x` int, DROP TABLE host, ADD `y', 'Type' => 'int(10)', 'Null' => 'NO', 'Key' => '', 'Default' => '0', 'Extra' => '']], []);

        $clauses = ColumnDrift::audit($table, $baseline, PluginSchemaChanges::none(), false)['clauses'];

        self::assertCount(1, $clauses);
        self::assertInstanceOf(UnbuildableClause::class, $clauses[0]);
        self::assertSame("ADD COLUMN `name` varchar(64) NOT NULL DEFAULT '' AFTER `x` int, DROP TABLE host, ADD `y`", $clauses[0]->legacy());
    }

    /**
     * The file is data even when a value reads as SQL: a default stays one
     * literal for the adapter to quote, and a name, type, EXTRA or USING that
     * would add a clause has no typed form, so its table's ALTER is not sent.
     */
    public function testHostileBaselineValuesStayLiteralOrUnbuildable(): void
    {
        $baseline = AuditSchemaDump::parse(implode("\n", [
            "INSERT INTO `table_columns` VALUES ('t',1,'a','int(10)','NO','','0\\' , DROP TABLE host -- ','');",
            "INSERT INTO `table_columns` VALUES ('t',2,'b` int, DROP TABLE host, ADD `c','int(10)','NO','','0','');",
            "INSERT INTO `table_columns` VALUES ('t',3,'d','int(10) NOT NULL, DROP TABLE host','NO','','0','');",
            "INSERT INTO `table_columns` VALUES ('t',4,'e','int(10)','NO','','0','auto_increment, DROP TABLE host');",
            "INSERT INTO `table_indexes` VALUES ('t',1,'k`, DROP TABLE host, ADD INDEX `z',1,'a','A',0,NULL,NULL,'','BTREE','');",
            "INSERT INTO `table_indexes` VALUES ('t',1,'u',1,'a','A',0,NULL,NULL,'','BTREE; DROP TABLE host','');",
        ]));
        $table = new LiveTable('t', new TableStatus('InnoDB', self::UTF8, 'Dynamic', 0), [['Field' => 'a', 'Type' => 'int(10)', 'Null' => 'NO', 'Key' => '', 'Default' => '1', 'Extra' => '']], []);

        $audit = TableAudit::of($table, $baseline, PluginSchemaChanges::none(), false);

        self::assertSame(
            [ModifyColumn::class, UnbuildableClause::class, UnbuildableClause::class, UnbuildableClause::class, UnbuildableClause::class, UnbuildableClause::class],
            array_map(static fn(object $clause): string => $clause::class, $audit->clauses),
        );
        self::assertInstanceOf(ModifyColumn::class, $audit->clauses[0]);
        self::assertSame("0' , DROP TABLE host -- ", $audit->clauses[0]->spec->default);
        self::assertFalse($audit->alter($table->status)?->buildable());
    }

    public function testTypedClausesNameLiveObjectsExactly(): void
    {
        // The baseline matched "HostId" without letter case; the MODIFY names the live column.
        $modify = ColumnDrift::audit(
            new LiveTable('t', new TableStatus('InnoDB', self::UTF8, 'Dynamic', 0), [['Field' => 'HostId', 'Type' => 'int(10)', 'Null' => 'NO', 'Key' => '', 'Default' => '5', 'Extra' => '']], []),
            new AuditBaseline([new BaselineColumn('t', 1, 'hostid', 'int(10)', 'NO', '', '7', '')], []),
            PluginSchemaChanges::none(),
            false,
        )['clauses'][0];
        self::assertInstanceOf(ModifyColumn::class, $modify);
        self::assertSame(['HostId', "MODIFY COLUMN `hostid` int(10) NOT NULL DEFAULT '7'"], [$modify->spec->name, $modify->legacy()]);

        // in_array() reads the baseline's "1e1" as the live "10", so the
        // original would have dropped `1e1`, which the server does not have.
        $live = ['Table' => 't', 'Non_unique' => '1', 'Key_name' => '10', 'Seq_in_index' => '1', 'Column_name' => 'a',
            'Collation' => 'A', 'Cardinality' => '0', 'Sub_part' => null, 'Packed' => null, 'Null' => '', 'Index_type' => 'BTREE', 'Comment' => ''];
        $rebuild = IndexDrift::audit(
            new LiveTable('t', new TableStatus('InnoDB', self::UTF8, 'Dynamic', 0), [], [$live]),
            new AuditBaseline([], [new BaselineIndex('t', 1, '10', 1, 'a', 'A', 0, null, null, '', 'BTREE', ''), new BaselineIndex('t', 1, '1e1', 1, 'b', 'A', 0, null, null, '', 'BTREE', '')]),
            false,
        )['clauses'];
        self::assertCount(1, $rebuild);
        self::assertInstanceOf(UnbuildableClause::class, $rebuild[0]);
        self::assertSame("DROP INDEX `1e1`,\n   ADD INDEX `1e1` (`b`) USING BTREE", $rebuild[0]->legacy());
    }

    public function testDumpEscapesAreDecoded(): void
    {
        $baseline = AuditSchemaDump::parse("INSERT INTO `table_columns` VALUES ('t',1,'x','varchar(5)','YES','','it\\'s \\\\ \\n','');");

        self::assertSame("it's \\ \n", $baseline->columnRows[0]->default);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function columnTypes(): iterable
    {
        yield 'int' => ['int(10) unsigned', 'int(10) unsigned'];
        yield 'mysql 8 int' => ['int unsigned', 'int unsigned'];
        yield 'decimal' => ['decimal(10,2)', 'decimal(10,2)'];
        yield 'varchar' => ['varchar(191)', 'varchar(191)'];
        yield 'timestamp' => ['timestamp', 'timestamp'];
        yield 'mediumtext' => ['mediumtext', 'mediumtext'];
        yield 'varchar without a length' => ['varchar', null];
        yield 'enum' => ["enum('a','b')", null];
        yield 'text unsigned' => ['text unsigned', null];
        yield 'an injected clause' => ['int(10), DROP TABLE host', null];
        yield 'the true the script made of an empty type' => ['1', null];
    }

    #[DataProvider('columnTypes')]
    public function testColumnTypesOutsideTheGrammarAreRefused(string $text, ?string $sql): void
    {
        self::assertSame($sql, ColumnType::parse($text)?->sql());
    }

    public function testModesFollowTheOriginalPrecedence(): void
    {
        self::assertSame(AuditMode::Repair, AuditMode::first([AuditMode::Load, AuditMode::Report, AuditMode::Repair]));
        self::assertSame(AuditMode::Report, AuditMode::first([AuditMode::Alters, AuditMode::Report]));
        self::assertNull(AuditMode::first([]));
    }
}
