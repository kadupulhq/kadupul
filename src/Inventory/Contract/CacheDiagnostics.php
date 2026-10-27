<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Contract;

/** Recorded cache rows within the actor's management-device scope. Never probes devices. */
interface CacheDiagnostics
{
    /**
     * @param array<string, string> $filters Validated filters from the diagnostic view
     * @return list<list<string|int|null>> At most $rows + 1 rows; the extra row is a paging lookahead
     */
    public function read(bool $poller, int $actorId, array $filters, int $rows, int $offset): array;
}
