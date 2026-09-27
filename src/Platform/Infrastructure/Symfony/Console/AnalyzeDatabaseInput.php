<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Console;

use Symfony\Component\Console\Attribute\Option;

/** MapInput builds this without its constructor and assigns each public property. */
final class AnalyzeDatabaseInput
{
    #[Option(description: 'On a remote collector, analyze its local database instead of the main one.')]
    public bool $local = false;

    #[Option(description: 'Accepted for compatibility; output is unchanged.', shortcut: 'd')]
    public bool $debug = false;

    #[Option(description: 'Operator account to act as (default: the admin_user setting).')]
    public ?string $as = null;

    #[Option(description: 'Emit a machine-readable result.')]
    public bool $json = false;
}
