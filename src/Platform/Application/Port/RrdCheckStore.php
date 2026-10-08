<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Port;

use Kadupul\Platform\Domain\RrdCheck\RrdCheckFilters;
use Kadupul\Platform\Domain\RrdCheck\RrdCheckPage;

interface RrdCheckStore
{
    public function enabled(): bool;
    public function defaultRows(): int;
    public function count(): int;
    public function list(RrdCheckFilters $filters): RrdCheckPage;

    /** Remove every recorded problem and return how many rows went. */
    public function purge(int $actorId): int;
}
