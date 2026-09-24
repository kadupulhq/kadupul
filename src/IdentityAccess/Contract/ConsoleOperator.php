<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Contract;

/**
 * The account a command-line run acts as. Command-line use only: it names an
 * account and applies the same account and realm checks as the web path, but
 * it does not authenticate anyone. Web code resolves actors through
 * ConsoleAccess, which this contract deliberately does not extend, so no
 * route can reach an operator chosen by a command-line flag.
 */
interface ConsoleOperator
{
    /**
     * Null or '' selects the admin_user setting. The account is checked on
     * the database the command works on, so a collector run against its local
     * database does not depend on reaching the main one.
     */
    public function select(?string $username, OperatorDatabase $database): void;

    /** Null when the selected account may not use the console at all. */
    public function actor(): ?Actor;

    public function canAdministerInstallation(Actor $actor): bool;
}
