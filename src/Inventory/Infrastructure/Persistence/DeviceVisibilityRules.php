<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Persistence;

use Kadupul\IdentityAccess\Contract\VisibilitySql;

/** Compatibility entry for Inventory's existing readers and locked writers. */
final class DeviceVisibilityRules
{
    public const MODE = VisibilitySql::MODE;
    public const USER_POLICY = VisibilitySql::USER_POLICY;
    public const GROUP_POLICY = VisibilitySql::GROUP_POLICY;
    public const POLICIES = VisibilitySql::POLICIES;

    /**
     * Validates the stored visibility mode setting and returns it as 1 to 4; a missing setting counts as 1.
     *
     * @throws \RuntimeException When the stored value is not a supported mode.
     */
    public static function mode(mixed $stored): int
    {
        return VisibilitySql::mode($stored);
    }

    /**
     * Builds the SQL condition that limits devices to those the policies allow.
     * The condition refers to the host table as "h" and the local graph table as "gl", so the query embedding it must join both under those aliases.
     *
     * @param array $policies Rows selected by POLICIES.
     * @param ?\Closure(string): string $exceptions Turns an exception subquery into the SQL list placed inside IN (...); an empty string means the policy has no exceptions of that type.
     */
    public static function predicate(int $mode, array $policies, ?\Closure $exceptions = null): string
    {
        return VisibilitySql::predicate($mode, $policies, $exceptions);
    }
}
