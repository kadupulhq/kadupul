<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

final readonly class ConversionOptions
{
    public bool $innodb;
    public bool $utf8;
    public bool $latin1;
    public bool $rebuild;
    public bool $dynamic;
    public bool $force;

    /**
     * @param list<ConversionFlag> $flags
     * @param ?string $table one table instead of every table cacti.sql declares
     * @param list<string> $skip tables that keep their engine
     * @param string $size row count at which a table is too large, as the operator typed it
     */
    public function __construct(array $flags, public ?string $table, public array $skip, public string $size)
    {
        $this->innodb = in_array(ConversionFlag::Innodb, $flags, true);
        $this->utf8 = in_array(ConversionFlag::Utf8, $flags, true);
        $this->latin1 = in_array(ConversionFlag::Latin1, $flags, true);
        $this->rebuild = in_array(ConversionFlag::Rebuild, $flags, true);
        $this->dynamic = in_array(ConversionFlag::Dynamic, $flags, true);
        $this->force = in_array(ConversionFlag::Force, $flags, true);
        // Checked in the original's order, so the first problem it would have
        // reported is the one reported here.
        if ($skip !== [] && $table !== null) {
            throw new InvalidConversionOptions(ConversionProblem::TableAndSkip);
        }
        if (!$this->innodb && !$this->utf8 && !$this->latin1) {
            throw new InvalidConversionOptions(ConversionProblem::NoConversion);
        }
        if (!ctype_digit($size)) {
            throw new InvalidConversionOptions(ConversionProblem::Size);
        }
    }

    public function rowLimit(): int
    {
        return (int) $this->size;
    }

    /** The "Converting Database Tables to ..." fragment, doubled space and all. */
    public function legacySummary(): string
    {
        $summary = $this->innodb ? 'InnoDB' : '';
        if ($this->utf8) {
            $summary .= ($summary === '' ? '' : ' and ') . ' utf8';
        }

        return $summary;
    }

    /**
     * convert_tables.php's per-table rule, quirks included. A table the server
     * does not list reads as all nulls, as the original's empty row did.
     */
    public function decide(string $table, ?TableStatus $status): TableChange|TableSkip
    {
        $convert = $this->rebuild;
        $innodb = false;
        // Skipped under --rebuild, so a rebuilt MyISAM table keeps its engine.
        if (!$convert && $this->innodb) {
            if ($status?->engine === 'MyISAM') {
                $convert = true;
                $innodb = true;
            } elseif ($status?->engine === 'Aria') {
                $convert = true;
            }
        }
        if (in_array($table, $this->skip, true)) {
            $innodb = false;
        }
        if (!$convert && $this->utf8) {
            $convert = $status?->collation !== 'utf8mb4_unicode_ci';
        }
        // No collation is plain "latin1", so --latin1 converts every table.
        if (!$convert && $this->latin1) {
            $convert = $status?->collation !== 'latin1';
        }
        if ($this->dynamic && $status?->rowFormat === 'Compact') {
            $convert = true;
        }
        if ($this->dynamic && $status?->rowFormat === 'Page') {
            $convert = false;
        }
        if (!$convert) {
            return TableSkip::NothingToChange;
        }
        // The original compared TABLE_ROWS < $size with $size a string, and
        // PHP reads null < '0' as '' < '0', which is true: an unknown row count
        // is never too many, even under --size=0.
        if (!$this->force && $status?->rows !== null && $status->rows >= $this->rowLimit()) {
            return TableSkip::TooManyRows;
        }
        $charset = match (true) {
            $this->utf8 => TableCharset::Utf8mb4,
            $this->latin1 => TableCharset::Latin1,
            default => null,
        };

        return new TableChange($this->dynamic, $charset, $this->innodb && $innodb);
    }
}
