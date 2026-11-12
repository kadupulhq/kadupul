<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Application\Port;

use Kadupul\Automation\Domain\GraphRule;
use Kadupul\Automation\Domain\GraphRuleChange;

interface GraphRuleStore
{
    /**
     * Data Queries by ID, and each Graph Type with its Data Query, ordered by name.
     *
     * @return array{queries: array<int, string>, graphTypes: array<int, array{query: int, name: string}>}
     */
    public function choices(): array;

    public function find(int $id): ?GraphRule;

    /**
     * Creates the rule when $id is null; otherwise replaces its settings when
     * the stored row still matches $revision. Criteria items are not changed.
     *
     * @throws \Kadupul\Automation\Domain\GraphRuleRejected when the Data Query or Graph Type no longer matches
     * @throws \InvalidArgumentException when the rule is missing or changed since $revision
     */
    public function save(int $actorId, ?int $id, GraphRuleChange $change, ?string $revision): int;
}
