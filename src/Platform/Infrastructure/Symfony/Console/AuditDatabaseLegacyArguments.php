<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Console;

use Kadupul\Platform\Application\ReadModel\AlterResult;
use Kadupul\Platform\Application\ReadModel\AuditOutcome;
use Kadupul\Platform\Application\ReadModel\AuditReport;
use Kadupul\Platform\Application\ReadModel\BaselineOutcome;
use Kadupul\Platform\Domain\Schema\AuditMode;
use Kadupul\Platform\Domain\Schema\AuditTableStatus;
use Kadupul\Platform\Domain\Schema\TableAudit;

final class AuditDatabaseLegacyArguments extends LegacyArguments
{
    public const string SEPARATOR = '---------------------------------------------------------------------------------------------';
    private const array OPTIONS = ['create', 'load', 'report', 'upgrade', 'repair', 'alters'];

    /** The long options audit_database.php gave getopt(), plus the shim's own --as. translate() reads them the way getopt() did. */
    #[\Override]
    protected function flags(): array
    {
        $flags = [];
        foreach (self::OPTIONS as $option) {
            $flags['--' . $option] = [$option, false];
        }

        return $flags + ['--as' => ['as', true]] + self::versionAndHelp();
    }

    /**
     * getopt('VvHh', [...]) as audit_database.php:28-42 called it, which
     * AuditDatabaseCommandTest checks against PHP's own getopt(): an unknown
     * option is ignored, the first argument that is not an option ends
     * parsing, short options cluster, "--report=x" still means --report and
     * "--report=" means nothing. The first version or help flag wins, as the
     * script's foreach exited on it.
     *
     * Two things differ from getopt(), as in the other shims. --as takes its
     * value only as --as=NAME; a bare or empty --as is refused rather than
     * falling back to admin_user, and "--as --report" cannot make --report
     * the operator.
     * --dry-run and --json, which only bin/console offers, are refused rather
     * than ignored, so "--repair --dry-run" cannot run a real repair.
     */
    #[\Override]
    public function translate(array $argv): array
    {
        if ($argv === []) {
            return [[], LegacyRequest::Usage];
        }
        $input = [];
        foreach ($argv as $argument) {
            if ($argument === '--' || strlen($argument) < 2 || $argument[0] !== '-') {
                break;
            }
            if ($argument[1] !== '-') {
                foreach (str_split(substr($argument, 1)) as $letter) {
                    if (in_array($letter, ['V', 'v', 'H', 'h'], true)) {
                        return [[], strtolower($letter) === 'v' ? LegacyRequest::Version : LegacyRequest::Help];
                    }
                }
                continue;
            }
            [$name, $value] = str_contains($argument, '=') ? explode('=', substr($argument, 2), 2) : [substr($argument, 2), null];
            if ($name === 'as') {
                if ($value === null || $value === '') {
                    throw new InvalidLegacyArgument($argument);
                }
                $input['--as'] = $value;
                continue;
            }
            if ($name === 'dry-run' || $name === 'json') {
                throw new InvalidLegacyArgument($argument);
            }
            if ($value === '') {
                continue;
            }
            if ($name === 'version' || $name === 'help') {
                return [[], $name === 'version' ? LegacyRequest::Version : LegacyRequest::Help];
            }
            if (in_array($name, self::OPTIONS, true)) {
                $input['--' . $name] = true;
            }
        }

        return [$input, null];
    }

    /**
     * The original had no such error, since getopt() ignored what it did not
     * know. help() already starts with a blank line, so none is added here.
     */
    #[\Override]
    public function invalid(string $argument): array
    {
        return ['ERROR: Invalid Parameter ' . $argument];
    }

    /** The original's help text after its version line, which the caller prepends. */
    #[\Override]
    public function help(): array
    {
        return ['', 'usage: audit_database.php --report | --repair [ --upgrade ]', '',
            'Kadupul utility for auditing and correcting your Kadupul database.  This utility can',
            'will scan your Kadupul database and report any problems in the schema that it finds.', '',
            'Options:',
            '    --report  - Report on any issues found in the audit of the database',
            '    --repair  - Repair any issues found during the audit of the database',
            '    --upgrade - Upgrade the Kadupul database before running', '',
            'Developer Options:',
            '    --create  - Initialize or Re-initialize the Audit Schema tables.',
            '    --load    - Take a pristine Kadupul install and create Audit Schema and file.',
            '    --alters  - Print out all the alter commands vs. executing for debugging.', ''];
    }

    /**
     * @param bool $alters --alters was given, which prefixes some lines with "-- " whatever the mode
     * @return list<string>
     */
    public function report(AuditReport $report, bool $alters, string $versionLine): array
    {
        if ($report->outcome === AuditOutcome::UpgradeRequired) {
            return ['WARNING: Kadupul must be upgraded first.  Use the --upgrade option to perform that upgrade'];
        }
        $lines = self::split($report->upgrade?->stdout ?? '');
        if ($report->outcome === AuditOutcome::UpgradeFailed) {
            return [...$lines, 'FATAL: Kadupul Upgrade Failed.  The audit was not run.'];
        }
        if ($report->outcome === AuditOutcome::NoMode) {
            return [...$lines, $versionLine, ...$this->help()];
        }
        $prefix = $alters ? '-- ' : '';
        if ($report->baseline === BaselineOutcome::CreateFailed) {
            return [...$lines, "Failed to create '" . $report->uncreated . "'"];
        }
        if ($report->mode === AuditMode::Load) {
            return [...$lines, ...self::load($report)];
        }
        $lines = [...$lines, ...self::baseline($report, $prefix)];

        return match ($report->mode) {
            AuditMode::Create => $lines,
            AuditMode::Report => [...$lines, ...self::findings($report)],
            default => [...$lines, ...self::scans($report, $prefix), ...self::alters($report, $prefix)],
        };
    }

    /** @return list<string> */
    private static function baseline(AuditReport $report, string $prefix): array
    {
        return match ($report->baseline) {
            BaselineOutcome::Loaded => [$prefix . 'SUCCESS: Loaded the Audit Schema'],
            BaselineOutcome::FileMissing => ['FATAL: Failed to find Audit Schema'],
            // The client's output followed "ERROR: "; the parser names the line instead.
            BaselineOutcome::Unparsable => ['FATAL: Failed Load the Audit Schema', 'ERROR: docs/audit_schema.sql line ' . $report->unparsableLine . ' does not parse'],
            default => ['FATAL: Failed Load the Audit Schema', 'ERROR: '],
        };
    }

    /** @return list<string> */
    private static function findings(AuditReport $report): array
    {
        $lines = [];
        foreach ($report->tables as $table) {
            $header = sprintf('Checking Table: %-45s', "'" . $table->table . "'");
            $lines[] = self::SEPARATOR;
            $lines = [...$lines, ...match (true) {
                $table->status === AuditTableStatus::Unknown => [$header . ' - Does not Exist.  Possible Plugin'],
                $table->status === AuditTableStatus::Plugin => [$header . ' - Plugin Detected'],
                $table->errors > 0 || $table->warnings > 0 => [$header, ...$table->findings, '', 'ERRORS: ' . $table->errors . ', WARNINGS: ' . $table->warnings],
                default => [$header . ' - Clean'],
            }];
        }
        // Only tables with a clause count; warnings alone read as clean.
        $fixable = array_any($report->tables, static fn(TableAudit $table): bool => $table->clauses !== []);

        return [...$lines, self::SEPARATOR, ...($fixable
            ? ['ERRORS are fixable using the --repair option.  WARNINGS will not be repaired', 'due to ambiguous use of the column.']
            : ['Audit was clean, no errors or warnings']), self::SEPARATOR];
    }

    /**
     * The original printed only the scan line here. A column it would have
     * narrowed now gets a line of its own, after its table's.
     *
     * @return list<string>
     */
    private static function scans(AuditReport $report, string $prefix): array
    {
        $lines = [];
        foreach ($report->tables as $table) {
            $lines[] = sprintf($prefix . 'Scanning Table: %-45s', "'" . $table->table . "'") . ' - Completed';
            foreach ($table->widened as $column) {
                $lines[] = $prefix . $column->line();
            }
        }

        return $lines;
    }

    /** @return list<string> */
    private static function alters(AuditReport $report, string $prefix): array
    {
        $lines = [];
        $good = 0;
        $bad = 0;
        foreach ($report->alters as $alter) {
            $lines[] = self::SEPARATOR;
            if ($alter['result'] === AlterResult::Planned) {
                // Only --alters plans without sending; a Repair whose alters were
                // never sent would be a dry run, which the shim refuses before this
                // point. Guard here too, so the "Repair Completed!" line below can
                // never describe a table this loop only proposed.
                if ($prefix === '') {
                    throw new \LogicException('A cli/ shim cannot ask for a dry run.');
                }
                $lines = [...$lines, '-- Proposed Alter for Table : ' . $alter['table'], '', ...self::split($alter['legacy'] . "\n"), ''];
            } elseif ($alter['result'] === AlterResult::Altered) {
                $good++;
                $lines[] = 'Executing Alter for Table : ' . $alter['table'] . ' - Success';
            } else {
                $bad++;
                $lines = [...$lines, 'Executing Alter for Table : ' . $alter['table'] . ' - Failed', ...self::split($alter['legacy'] . "\n")];
            }
        }
        $lines[] = self::SEPARATOR;
        $lines[] = match (true) {
            $good === 0 && $bad === 0 => $prefix . 'Repair Completed!  No changes performed.',
            $bad > 0 => 'Repair Completed!  ' . $good . ' Alters succeeded and ' . $bad . ' failed!',
            default => 'Repair Completed!  All ' . $good . ' Alters succeeded!',
        };

        return $lines;
    }

    /** @return list<string> */
    private static function load(AuditReport $report): array
    {
        $lines = array_map(static fn(string $table): string => 'Importing Table: ' . $table . ' - Done', $report->imported);
        if ($report->dumpPath === null) {
            return [...$lines, '', 'FATAL: Docs directory does not exist!', ''];
        }

        return [...$lines, '', 'Exporting Table Audit Table Creation Logic to ' . $report->dumpPath,
            $report->exported === true ? 'Finished Creating Audit Schema' : 'Finished Creating Audit Schema with ERROR', ''];
    }

    /**
     * Printed text as lines for ResultRenderer, which ends each with a newline.
     *
     * @return list<string>
     */
    private static function split(string $text): array
    {
        return $text === '' ? [] : explode("\n", str_ends_with($text, "\n") ? substr($text, 0, -1) : $text);
    }
}
