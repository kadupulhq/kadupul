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
 * The target is optional in the definition because a shim's help request passes
 * none; the command rejects a missing one as invalid input.
 */
final class ListPermissionsInput
{
    #[Argument(description: 'What to list: users, trees, hosts, graphs or graph-templates.')]
    public ?string $target = null;

    #[Option(description: 'The device whose graphs to list, for the graphs target.')]
    public ?string $hostId = null;

    // Not --quiet: Symfony reserves that name for its own verbosity option.
    #[Option(description: 'Leave out the heading and the closing blank line.')]
    public bool $noHeadings = false;

    #[Option(description: 'On a remote collector, read its local database instead of the main one.')]
    public bool $local = false;

    #[Option(description: 'Operator account to act as (default: the admin_user setting).')]
    public ?string $as = null;

    #[Option(description: 'Emit a machine-readable result.')]
    public bool $json = false;
}
