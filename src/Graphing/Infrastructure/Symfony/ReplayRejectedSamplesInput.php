<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Symfony\Component\Console\Attribute\Option;

/** The options of kadupul:rejected-samples:replay. MapInput builds this without its constructor and assigns each public property. */
final class ReplayRejectedSamplesInput
{
    #[Option(name: 'local-data-id', description: 'Replay the rejected samples of one data source (a positive integer).')]
    public ?string $localDataId = null;

    #[Option(description: 'Replay the rejected samples of every data source.')]
    public bool $all = false;

    #[Option(name: 'dry-run', description: 'Report the count without moving anything.')]
    public bool $dryRun = false;

    #[Option(description: 'Operator account to act as (default: the admin_user setting).')]
    public ?string $as = null;

    #[Option(description: 'Emit a machine-readable result.')]
    public bool $json = false;
}
