<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\Platform\Application\ReadModel\ConversionOutcome;
use Kadupul\Platform\Application\ReadModel\ConversionReport;
use Kadupul\Platform\Application\ReadModel\TableOutcome;
use Kadupul\Platform\Application\ReadModel\TableResult;
use Kadupul\Platform\Domain\Schema\ConversionOptions;
use Kadupul\Platform\Infrastructure\Symfony\Console\ConvertTablesLegacyArguments;

/** What lib/installer.php needs from one queued table's conversion. */
final readonly class InstallerTableResult
{
    /**
     * @param string $output what cli/convert_tables.php printed for the same
     *     table, which the installer's debug log line has always carried
     * @param ?string $error the class of the exception that stopped the run,
     *     never its message, which can carry connection details
     */
    private function __construct(private ?TableResult $result, public string $output, public ?string $error = null) {}

    public static function table(TableOutcome $outcome, ConversionOptions $options): self
    {
        return new self($outcome->result, self::text(ConversionOutcome::Completed, [$outcome->line()], $options));
    }

    public static function stopped(ConversionOutcome $outcome, ConversionOptions $options): self
    {
        if ($outcome === ConversionOutcome::Completed) {
            throw new \LogicException('A completed conversion reports its table instead.');
        }

        return new self(null, self::text($outcome, [], $options));
    }

    /** The shim's own text for a run that threw, and only the exception's class. */
    public static function failed(\Throwable $error): self
    {
        return new self(null, "ERROR: Table conversion failed\n", $error::class);
    }

    /**
     * The installer matched "Converting table" and "Successful", or "Skipped
     * table", in the script's output. Only a converted table printed a match:
     * the script said "Skipping Table", never "Skipped table".
     */
    public function dequeues(): bool
    {
        return $this->result === TableResult::Converted;
    }

    /** @param list<array{name: string, result: TableResult, rows: ?int, statement: ?string}> $tables */
    private static function text(ConversionOutcome $outcome, array $tables, ConversionOptions $options): string
    {
        $lines = (new ConvertTablesLegacyArguments())->report(new ConversionReport(false, $outcome, null, $tables, false), $options, '');

        // The script printed its innodb_file_per_table refusal without a newline.
        return implode("\n", $lines) . ($outcome === ConversionOutcome::FilePerTableDisabled ? '' : "\n");
    }
}
