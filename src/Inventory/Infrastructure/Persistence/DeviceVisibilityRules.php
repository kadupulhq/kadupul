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

    public static function mode(mixed $stored): int
    {
        return VisibilitySql::mode($stored);
    }

    public static function predicate(int $mode, array $policies, ?\Closure $exceptions = null): string
    {
        return VisibilitySql::predicate($mode, $policies, $exceptions);
    }
}
