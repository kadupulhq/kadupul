<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Fixtures;

use Kadupul\IdentityAccess\Application\Port\PermissionCatalog;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** A PermissionCatalog with a few fixed rows. */
final class InMemoryPermissionCatalog implements PermissionCatalog
{
    #[\Override]
    public function users(OperatorDatabase $database): array
    {
        return [['id' => '1', 'username' => 'admin', 'full_name' => 'Administrator'], ['id' => '7', 'username' => 'ops', 'full_name' => '']];
    }

    #[\Override]
    public function trees(OperatorDatabase $database): array
    {
        return [['id' => '1', 'sort_type' => '2', 'name' => 'Default Tree'], ['id' => '2', 'sort_type' => '9', 'name' => 'Odd']];
    }

    #[\Override]
    public function hosts(OperatorDatabase $database): array
    {
        return [['id' => '5', 'hostname' => 'router.example', 'host_template_id' => '3', 'description' => 'Router']];
    }

    #[\Override]
    public function hostGraphs(OperatorDatabase $database, int $hostId): array
    {
        return $hostId === 5 ? [['id' => '9', 'name' => 'Traffic', 'template_name' => 'Interface']] : [];
    }

    #[\Override]
    public function graphTemplates(OperatorDatabase $database): array
    {
        return [['id' => '4', 'name' => 'Interface - Traffic']];
    }
}
