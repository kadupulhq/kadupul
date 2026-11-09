<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Port;

use Kadupul\IdentityAccess\Application\Query\LegacyPasswordAccount;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** Reads, never changes, the local accounts whose stored password local login would check as unsalted MD5. */
interface LegacyPasswordAccounts
{
    /**
     * Only realm 0 rows: local login verifies no other realm against
     * user_auth.password. The stored value never leaves the adapter.
     *
     * @return list<LegacyPasswordAccount> ordered by id
     */
    public function withUnsaltedMd5(OperatorDatabase $database): array;
}
