<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Console;

use Symfony\Component\Console\Attribute\Option;

/** MapInput builds this without its constructor and assigns each public property. */
final class WidenIdColumnsInput
{
    #[Option(description: 'On a remote collector, widen its local database instead of the main one.')]
    public bool $local = false;

    #[Option(description: 'Print each column checked, in legacy output.')]
    public bool $debug = false;

    #[Option(description: 'Operator account to act as (default: the admin_user setting).')]
    public ?string $as = null;

    #[Option(description: 'List the statements without running them.')]
    public bool $dryRun = false;

    #[Option(description: 'Emit a machine-readable result.')]
    public bool $json = false;
}
