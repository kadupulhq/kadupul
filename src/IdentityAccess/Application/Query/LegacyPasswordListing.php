<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Query;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** The database that was read and the local accounts found there with a legacy MD5 password. */
final readonly class LegacyPasswordListing
{
    /**
     * @param list<LegacyPasswordAccount> $accounts
     */
    public function __construct(public OperatorDatabase $database, public array $accounts) {}
}
