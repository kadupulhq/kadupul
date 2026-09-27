<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Console;

use Kadupul\Platform\Domain\Schema\ConversionFlag;
use Kadupul\Platform\Domain\Schema\ConversionOptions;
use Symfony\Component\Console\Attribute\Option;

/** MapInput builds this without its constructor and assigns each public property. */
final class ConvertTablesInput
{
    #[Option(description: 'Convert MyISAM tables to InnoDB.')]
    public bool $innodb = false;

    #[Option(description: 'Convert tables that are not utf8mb4_unicode_ci to it.')]
    public bool $utf8 = false;

    #[Option(description: 'Convert tables to latin1.')]
    public bool $latin1 = false;

    #[Option(description: 'Convert only this table.')]
    public ?string $table = null;

    #[Option(description: 'Space-separated tables that keep their engine.')]
    public ?string $skipInnodb = null;

    #[Option(description: 'Skip tables with at least this many rows.')]
    public string $size = '1000000';

    #[Option(description: 'Rebuild tables even when nothing else changes.')]
    public bool $rebuild = false;

    #[Option(description: 'Move Compact tables to the Dynamic row format.')]
    public bool $dynamic = false;

    #[Option(description: 'Convert tables whatever their size.')]
    public bool $force = false;

    #[Option(description: 'On a remote collector, convert its local database instead of the main one.')]
    public bool $local = false;

    #[Option(description: 'Accepted for compatibility; output is unchanged.')]
    public bool $debug = false;

    #[Option(description: 'Operator account to act as (default: the admin_user setting).')]
    public ?string $as = null;

    #[Option(description: 'List the statements without running them.')]
    public bool $dryRun = false;

    #[Option(description: 'Emit a machine-readable result.')]
    public bool $json = false;

    public function options(): ConversionOptions
    {
        $flags = array_values(array_filter([
            $this->innodb ? ConversionFlag::Innodb : null,
            $this->utf8 ? ConversionFlag::Utf8 : null,
            $this->latin1 ? ConversionFlag::Latin1 : null,
            $this->rebuild ? ConversionFlag::Rebuild : null,
            $this->dynamic ? ConversionFlag::Dynamic : null,
            $this->force ? ConversionFlag::Force : null,
        ]));

        // The original read an empty --table as "every table" and split
        // --skip-innodb on single spaces; both are kept.
        return new ConversionOptions($flags, $this->table === '' ? null : $this->table, $this->skipInnodb === null ? [] : explode(' ', $this->skipInnodb), $this->size);
    }
}
