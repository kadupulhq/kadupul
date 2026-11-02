<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Contract;

/** Management scopes are evaluated on the connection used by their reader. */
interface ManagementVisibility
{
    /** @return array{graphs: string, devices: string} */
    public function forActor(int $actorId): array;
}
