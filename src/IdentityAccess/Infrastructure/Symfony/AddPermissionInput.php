<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony;

use Symfony\Component\Console\Attribute\Option;

/**
 * MapInput builds this without its constructor and assigns each public property.
 * The ids stay strings because add_perms.php read them that way and reported a
 * bad one as typed; the command parses them through ItemPermissionRequest.
 */
final class AddPermissionInput
{
    #[Option(description: 'Id of the user to give access to.')]
    public ?string $userId = null;

    #[Option(description: 'Kind of item: graph, tree, host or graph_template.')]
    public ?string $itemType = null;

    #[Option(description: 'Id of the item.')]
    public ?string $itemId = null;

    #[Option(description: 'On a remote collector, grant in its local database instead of the main one.')]
    public bool $local = false;

    #[Option(description: 'Operator account to act as (default: the admin_user setting).')]
    public ?string $as = null;

    #[Option(description: 'Run every check and report the grant without making it.')]
    public bool $dryRun = false;

    #[Option(description: 'Emit a machine-readable result.')]
    public bool $json = false;
}
