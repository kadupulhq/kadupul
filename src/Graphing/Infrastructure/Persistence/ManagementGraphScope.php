<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Persistence;

use Kadupul\IdentityAccess\Contract\ManagementVisibility;

/** Current management predicates, read on the same connection as the catalog. */
final readonly class ManagementGraphScope
{
    public function __construct(private ManagementVisibility $visibility) {}

    /** @return array{graphs: string, devices: string} */
    public function forActor(int $actorId): array
    {
        return $this->visibility->forActor($actorId);
    }
}
