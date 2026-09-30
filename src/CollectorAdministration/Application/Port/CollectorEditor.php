<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Application\Port;

use Kadupul\CollectorAdministration\Domain\CollectorDetails;

interface CollectorEditor
{
    public function find(int $id): ?CollectorDetails;

    /** @param array<string, mixed> $values */
    public function save(int $actorId, ?int $id, array $values): int;
}
