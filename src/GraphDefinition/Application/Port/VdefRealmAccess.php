<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Application\Port;

/** Answers whether a user holds the permission to manage graph definitions. */
interface VdefRealmAccess
{
    /** True when the user holds the definitions realm directly or through an enabled group. */
    public function canManageDefinitions(int $actorId): bool;
}
