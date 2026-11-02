<?php

declare(strict_types=1);

/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Installer;

interface OperationalEvidence
{
    /** @param list<int> $graphIds
     *  @return array<string, mixed> Bounded observations, never a claim of live device or rendering verification.
     */
    public function inspect(int $actorId, array $graphIds): array;
}
