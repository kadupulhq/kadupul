<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Contract;

/** Names the account a command-line run acts as, before ConsoleAccess resolves it. */
interface ConsoleOperator
{
    /**
     * Null or '' selects the admin_user setting. The account is checked on
     * the database the command works on, so a collector run against its local
     * database does not depend on reaching the main one.
     */
    public function select(?string $username, OperatorDatabase $database): void;
}
