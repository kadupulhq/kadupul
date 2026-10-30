<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Symfony\Component\Console\Attribute\Option;

/** MapInput builds this without its constructor and assigns each public property. */
final class RemoveBrokenGraphsInput
{
    #[Option(description: 'Report the graph templates and their broken graphs (the default).')]
    public bool $report = false;

    #[Option(description: 'Remove the broken graphs, and the data sources they reference.')]
    public bool $remove = false;

    #[Option(description: 'Accepted for compatibility; it prints nothing extra.')]
    public bool $debug = false;

    #[Option(description: 'On a remote collector, use its local database instead of the main one.')]
    public bool $local = false;

    #[Option(description: 'Operator account to act as (default: the admin_user setting).')]
    public ?string $as = null;

    #[Option(description: 'Emit a machine-readable result.')]
    public bool $json = false;
}
