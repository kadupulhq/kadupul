<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Console;

use Kadupul\Platform\Application\ReadModel\ConversionOutcome;
use Kadupul\Platform\Application\ReadModel\ConversionReport;
use Kadupul\Platform\Application\ReadModel\TableResult;
use Kadupul\Platform\Domain\Schema\ConversionOptions;
use Kadupul\Platform\Domain\Schema\ConversionProblem;

final class ConvertTablesLegacyArguments extends LegacyArguments
{
    private const string WHOLE_NUMBER = '/^\d+$/D';

    #[\Override]
    protected function flags(): array
    {
        // --installer is left out: the original required a path without its
        // separator and died, so nothing can depend on it. --as is the shim's own.
        return [
            '-d' => ['debug', false], '--debug' => ['debug', false],
            '-r' => ['rebuild', false], '--rebuild' => ['rebuild', false],
            '--dynamic' => ['dynamic', false], '--local' => ['local', false],
            '-s' => ['size', true, self::WHOLE_NUMBER], '--size' => ['size', true, self::WHOLE_NUMBER],
            '-t' => ['table', true], '--table' => ['table', true],
            '-i' => ['innodb', false], '--innodb' => ['innodb', false],
            '-l' => ['latin1', false], '--latin1' => ['latin1', false],
            '-n' => ['skip-innodb', true], '--skip-innodb' => ['skip-innodb', true],
            '-f' => ['force', false], '--force' => ['force', false],
            '-u' => ['utf8', false], '--utf8' => ['utf8', false],
            '--as' => ['as', true],
            '--version' => [null, false], '-V' => [null, false], '-v' => [null, false],
            '--help' => [null, false], '-H' => [null, false], '-h' => [null, false],
        ];
    }

    #[\Override]
    protected function special(string $flag): LegacyRequest
    {
        return in_array($flag, ['--version', '-V', '-v'], true) ? LegacyRequest::Version : LegacyRequest::Help;
    }

    /** The original's help text after its version line, which the caller prepends. */
    #[\Override]
    public function help(): array
    {
        return ['', 'usage: convert_tables.php [--debug] [--innodb] [--utf8] [--latin1] [--table=N] [--size=N] [--rebuild] [--dynamic]', '',
            'A utility to convert a Kadupul Database from MyISAM to the InnoDB table format.',
            'MEMORY tables are not converted to InnoDB in this process.', '',
            'Required (one or more):',
            '-i | --innodb  - Convert any MyISAM tables to InnoDB',
            '-u | --utf8    - Convert any non-UTF8 tables to utf8mb4_unicode_ci',
            '-l | --latin1  - Convert any non-latin1 tables to latin1', '',
            'Optional:',
            '-t | --table=S - The name of a single table to change',
            '-n | --skip-innodb="table1 table2 ..." - Skip converting tables to InnoDB',
            '-s | --size=N  - The largest table size in records to convert.  Default is 1,000,000 rows.',
            '-r | --rebuild - Will compress/optimize existing InnoDB tables if found',
            '     --dynamic - Convert a table to Dynamic row format if available',
            '     --local   - Perform the action on the Remote Data Collector if run from there',
            '-f | --force   - Proceed with conversion regardless of table size', '',
            '-d | --debug   - Display verbose output during execution', ''];
    }

    #[\Override]
    public function invalid(string $argument): array
    {
        return ['ERROR: Invalid Parameter ' . $argument, ''];
    }

    /** @return list<string> */
    public function problem(ConversionProblem $problem, string $versionLine): array
    {
        $error = match ($problem) {
            ConversionProblem::TableAndSkip => ['ERROR: You can not specify a single table and skip tables at the same time.', ''],
            ConversionProblem::NoConversion => ['ERROR: Must select either UTF8, LATIN1 or InnoDB conversion.', ''],
            // The shim's pattern rejects a bad size before this can run.
            ConversionProblem::Size => $this->invalid('--size'),
        };

        return [...$error, $versionLine, ...$this->help()];
    }

    /** @return list<string> */
    public function report(ConversionReport $report, ConversionOptions $options, string $versionLine): array
    {
        $lines = ['NOTE: Repairing Tables for ' . ($report->main ? 'Main' : 'Local') . ' Database'];
        if ($report->outcome === ConversionOutcome::SkipTableMissing) {
            return [...$lines, 'ERROR: Skip Table ' . $report->missingSkipTable . ' does not Exist.  Can not continue.', '', $versionLine, ...$this->help()];
        }
        $lines[] = 'Converting Database Tables to ' . $options->legacySummary() . " with less than '" . $options->size . "' Records";
        if ($report->outcome === ConversionOutcome::InnodbDisabled) {
            return [...$lines, 'InnoDB Engine is not enabled'];
        }
        if ($report->outcome === ConversionOutcome::FilePerTableDisabled) {
            return [...$lines, 'innodb_file_per_table not enabled'];
        }
        foreach ($report->tables as $table) {
            $lines[] = match ($table['result']) {
                TableResult::Converted => "Converting Table > '" . $table['name'] . "' Successful",
                TableResult::Failed => "Converting Table > '" . $table['name'] . "' Failed",
                TableResult::TooLarge => "Skipping Table > '" . $table['name'] . "' too many rows '" . ($table['rows'] ?? '') . "'",
                TableResult::Skipped => "Skipping Table > '" . $table['name'] . "'",
                TableResult::Planned => throw new \LogicException('A cli/ shim cannot ask for a dry run.'),
            };
        }

        return $lines;
    }
}
