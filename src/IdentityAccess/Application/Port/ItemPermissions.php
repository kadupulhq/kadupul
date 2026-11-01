<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Port;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\IdentityAccess\Domain\ItemType;

/** Checks the rows a permission names and records the grant. */
interface ItemPermissions
{
    /** Whether a user account with this id exists in the given database. */
    public function userExists(OperatorDatabase $database, int $userId): bool;

    /** Whether the graph, tree, device or graph template with this id exists in the given database. */
    public function itemExists(OperatorDatabase $database, ItemType $type, int $itemId): bool;

    /**
     * Gives the user access to the item through the same write the web uses,
     * which ends the user's cached permissions and remembered logins. False
     * when the write was refused.
     *
     * @phpstan-impure
     * @throws \RuntimeException when the write's outcome is unknown
     */
    public function grant(OperatorDatabase $database, int $userId, ItemType $type, int $itemId): bool;
}
