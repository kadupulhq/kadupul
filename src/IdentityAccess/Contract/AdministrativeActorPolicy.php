<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Contract;

/** Rechecks an authenticated actor inside the caller's mutation transaction. */
interface AdministrativeActorPolicy
{
    public function authorize(int $actorId, #[\SensitiveParameter] string $credential): Actor;
}
