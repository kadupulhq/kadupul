<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Query;

/**
 * A local account whose stored password is still an unsalted MD5 digest. It
 * deliberately carries no part of the stored value.
 */
final readonly class LegacyPasswordAccount
{
    /**
     * @param ?string $lastLogin time of the latest successful login in user_log, as the database
     *                           returns it; null when user_log holds none (it may have been purged)
     */
    public function __construct(public int $id, public string $username, public bool $enabled, public ?string $lastLogin) {}
}
