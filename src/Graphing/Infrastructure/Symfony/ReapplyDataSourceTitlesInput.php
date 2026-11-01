<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Symfony\Component\Console\Attribute\Option;

/**
 * MapInput builds this without its constructor and assigns each public property.
 * The device selector is optional in the definition because a shim's help or
 * version request passes none; the command rejects a missing one as invalid input.
 */
final class ReapplyDataSourceTitlesInput
{
    #[Option(description: 'Devices whose data sources to rename: all, 0, or a comma-separated list of device ids.')]
    public ?string $hostId = null;

    #[Option(description: 'Only data sources whose title, data id, data template or data input method name contains this text.')]
    public ?string $filter = null;

    #[Option(description: 'Print each data source as it is renamed.')]
    public bool $debug = false;

    #[Option(description: 'On a remote collector, rename in its local database instead of the main one.')]
    public bool $local = false;

    #[Option(description: 'Operator account to act as (default: the admin_user setting).')]
    public ?string $as = null;

    #[Option(description: 'List the data sources that would be renamed without renaming them.')]
    public bool $dryRun = false;

    #[Option(description: 'Emit a machine-readable result.')]
    public bool $json = false;
}
