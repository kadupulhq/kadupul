<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Port;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** The rows an operator picks from when granting access, in the order add_perms.php listed them. */
interface PermissionCatalog
{
    /** @return list<array{id: string, username: string, full_name: string}> */
    public function users(OperatorDatabase $database): array;

    /** @return list<array{id: string, sort_type: string, name: string}> */
    public function trees(OperatorDatabase $database): array;

    /** @return list<array{id: string, hostname: string, host_template_id: string, description: string}> */
    public function hosts(OperatorDatabase $database): array;

    /** @return list<array{id: string, name: string, template_name: string}> */
    public function hostGraphs(OperatorDatabase $database, int $hostId): array;

    /** @return list<array{id: string, name: string}> */
    public function graphTemplates(OperatorDatabase $database): array;
}
