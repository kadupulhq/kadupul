<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Query;

use Kadupul\IdentityAccess\Application\Command\UserCommandAccess;
use Kadupul\IdentityAccess\Application\Command\UserManagementDenied;
use Kadupul\IdentityAccess\Application\Port\LegacyPasswordAccounts;

/**
 * Lists the local accounts that still store an unsalted MD5 password, so an
 * operator can see them. It changes nothing: such a password keeps working
 * and is rehashed by the next successful login.
 */
final readonly class ListLegacyPasswordAccounts
{
    private const string ACTION = 'user.legacy-password.list';

    public function __construct(private UserCommandAccess $access, private LegacyPasswordAccounts $accounts) {}

    /**
     * @param ?string $operator account to act as; null means the admin_user setting
     * @throws UserManagementDenied
     */
    public function __invoke(bool $local, ?string $operator): LegacyPasswordListing
    {
        // The same check as the user tools: realm 1 (User Management) plus console access.
        $database = $this->access->select(self::ACTION, $local, $operator, true)->database;

        return new LegacyPasswordListing($database, $this->accounts->withUnsaltedMd5($database));
    }
}
