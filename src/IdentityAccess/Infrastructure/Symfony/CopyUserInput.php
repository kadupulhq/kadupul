<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;

/**
 * MapInput builds this without its constructor and assigns each public property.
 * The usernames are optional in the definition because a shim's help or version
 * request passes none; the command rejects a missing one as invalid input.
 */
final class CopyUserInput
{
    #[Argument(description: 'Username of the local account to copy.')]
    public ?string $template = null;

    #[Argument(description: 'Username of the new account.')]
    public ?string $new = null;

    #[Option(description: 'On a remote collector, copy in its local database instead of the main one.')]
    public bool $local = false;

    #[Option(description: 'Operator account to act as (default: the admin_user setting).')]
    public ?string $as = null;

    #[Option(description: 'Report what would be copied without copying it.')]
    public bool $dryRun = false;

    #[Option(description: 'Emit a machine-readable result.')]
    public bool $json = false;
}
