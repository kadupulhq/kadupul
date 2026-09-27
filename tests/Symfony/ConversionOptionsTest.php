<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Domain\Schema\ConversionFlag as Flag;
use Kadupul\Platform\Domain\Schema\ConversionOptions;
use Kadupul\Platform\Domain\Schema\ConversionProblem;
use Kadupul\Platform\Domain\Schema\InvalidConversionOptions;
use Kadupul\Platform\Domain\Schema\TableChange;
use Kadupul\Platform\Domain\Schema\TableCharset;
use Kadupul\Platform\Domain\Schema\TableSkip;
use Kadupul\Platform\Domain\Schema\TableStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConversionOptionsTest extends TestCase
{
    /**
     * @param list<Flag> $flags
     * @param list<string> $skip
     */
    #[DataProvider('decisions')]
    public function testDecisionsFollowTheOriginalScript(?TableStatus $status, array $flags, array $skip, string $size, TableChange|TableSkip $expected): void
    {
        self::assertEquals($expected, (new ConversionOptions($flags, null, $skip, $size))->decide('t', $status));
    }

    /** @return iterable<string, array{?TableStatus, list<Flag>, list<string>, string, TableChange|TableSkip}> */
    public static function decisions(): iterable
    {
        $myisam = new TableStatus('MyISAM', 'latin1_swedish_ci', 'Fixed', 2);
        $aria = new TableStatus('Aria', 'utf8mb4_unicode_ci', 'Page', 2);
        $innodb = new TableStatus('InnoDB', 'utf8mb4_unicode_ci', 'Dynamic', 2);
        $compact = new TableStatus('InnoDB', 'utf8mb4_unicode_ci', 'Compact', 2);
        $limit = '1000000';
        yield 'MyISAM moves to InnoDB' => [$myisam, [Flag::Innodb], [], $limit, new TableChange(false, null, true)];
        yield 'Aria converts but keeps its engine' => [$aria, [Flag::Innodb], [], $limit, new TableChange(false, null, false)];
        yield 'InnoDB needs nothing' => [$innodb, [Flag::Innodb], [], $limit, TableSkip::NothingToChange];
        yield 'a skip-listed MyISAM table still gets a bare ALTER' => [$myisam, [Flag::Innodb], ['t'], $limit, new TableChange(false, null, false)];
        yield 'rebuild never changes the engine' => [$myisam, [Flag::Innodb, Flag::Rebuild], [], $limit, new TableChange(false, null, false)];
        yield 'utf8 converts other collations' => [$myisam, [Flag::Utf8], [], $limit, new TableChange(false, TableCharset::Utf8mb4, false)];
        yield 'utf8 leaves utf8mb4_unicode_ci alone' => [$innodb, [Flag::Utf8], [], $limit, TableSkip::NothingToChange];
        yield 'latin1 converts every real collation' => [$innodb, [Flag::Latin1], [], $limit, new TableChange(false, TableCharset::Latin1, false)];
        yield 'utf8 wins over latin1 in the statement' => [$myisam, [Flag::Utf8, Flag::Latin1], [], $limit, new TableChange(false, TableCharset::Utf8mb4, false)];
        yield 'dynamic converts Compact tables' => [$compact, [Flag::Innodb, Flag::Dynamic], [], $limit, new TableChange(true, null, false)];
        yield 'dynamic never touches Page tables' => [$aria, [Flag::Innodb, Flag::Dynamic], [], $limit, TableSkip::NothingToChange];
        yield 'as many rows as the limit is too many' => [$myisam, [Flag::Innodb], [], '2', TableSkip::TooManyRows];
        yield 'force ignores the limit' => [$myisam, [Flag::Innodb, Flag::Force], [], '2', new TableChange(false, null, true)];
        yield 'an unlisted table has no engine to change' => [null, [Flag::Innodb], [], $limit, TableSkip::NothingToChange];
        yield 'an unlisted table has no collation, so utf8 converts it' => [null, [Flag::Utf8], [], $limit, new TableChange(false, TableCharset::Utf8mb4, false)];
        yield 'zero rows is too many for a zero limit' => [new TableStatus('MyISAM', 'latin1_swedish_ci', 'Fixed', 0), [Flag::Utf8], [], '0', TableSkip::TooManyRows];
        yield 'an unknown row count is below even a zero limit' => [new TableStatus('MyISAM', 'latin1_swedish_ci', 'Fixed', null), [Flag::Utf8], [], '0', new TableChange(false, TableCharset::Utf8mb4, false)];
        yield 'an unlisted table is below even a zero limit' => [null, [Flag::Utf8], [], '0', new TableChange(false, TableCharset::Utf8mb4, false)];
    }

    public function testClausesAreInTheOriginalOrder(): void
    {
        self::assertSame(['ROW_FORMAT=Dynamic', 'CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', 'ENGINE=InnoDB'], (new TableChange(true, TableCharset::Utf8mb4, true))->clauses());
        self::assertSame(['CONVERT TO CHARACTER SET latin1'], (new TableChange(false, TableCharset::Latin1, false))->clauses());
        self::assertSame([], (new TableChange(false, null, false))->clauses());
    }

    public function testTheFailureLineKeepsTheOriginalText(): void
    {
        self::assertSame(
            "FATAL: Conversion of Table 't' Failed.  Command: 'ALTER TABLE `t`  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, ENGINE=Innodb'",
            (new TableChange(true, TableCharset::Utf8mb4, true))->legacyFailure('t'),
        );
        self::assertSame("FATAL: Conversion of Table 't' Failed.  Command: 'ALTER TABLE `t`  ENGINE=Innodb'", (new TableChange(false, null, true))->legacyFailure('t'));
        self::assertSame("FATAL: Conversion of Table 't' Failed.  Command: 'ALTER TABLE `t` '", (new TableChange(true, null, false))->legacyFailure('t'));
    }

    /** @param list<Flag> $flags */
    #[DataProvider('summaries')]
    public function testTheSummaryKeepsTheOriginalSpacing(array $flags, string $expected): void
    {
        self::assertSame($expected, (new ConversionOptions($flags, null, [], '1000000'))->legacySummary());
    }

    /** @return iterable<string, array{list<Flag>, string}> */
    public static function summaries(): iterable
    {
        yield 'innodb' => [[Flag::Innodb], 'InnoDB'];
        yield 'utf8' => [[Flag::Utf8], ' utf8'];
        yield 'both' => [[Flag::Innodb, Flag::Utf8], 'InnoDB and  utf8'];
        yield 'latin1 is never named' => [[Flag::Latin1], ''];
    }

    /**
     * @param list<Flag> $flags
     * @param list<string> $skip
     */
    #[DataProvider('problems')]
    public function testProblemsAreReportedInTheOriginalOrder(array $flags, ?string $table, array $skip, string $size, ConversionProblem $expected): void
    {
        try {
            new ConversionOptions($flags, $table, $skip, $size);
            self::fail('Expected invalid options');
        } catch (InvalidConversionOptions $invalid) {
            self::assertSame($expected, $invalid->problem);
        }
    }

    /** @return iterable<string, array{list<Flag>, ?string, list<string>, string, ConversionProblem}> */
    public static function problems(): iterable
    {
        yield 'table and skip come first' => [[], 't', ['u'], 'x', ConversionProblem::TableAndSkip];
        yield 'then a missing conversion' => [[Flag::Force], null, [], 'x', ConversionProblem::NoConversion];
        yield 'then a size with an exponent' => [[Flag::Innodb], null, [], '1e3', ConversionProblem::Size];
        yield 'or a negative size' => [[Flag::Innodb], null, [], '-1', ConversionProblem::Size];
        yield 'or an empty size' => [[Flag::Innodb], null, [], '', ConversionProblem::Size];
    }
}
