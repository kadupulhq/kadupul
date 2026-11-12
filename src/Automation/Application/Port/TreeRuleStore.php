<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Application\Port;

use Kadupul\Automation\Domain\TreeRule;
use Kadupul\Automation\Domain\TreeRuleChange;

interface TreeRuleStore
{
    /**
     * Trees by ID ordered by name, and each tree's headers in tree order with their depth.
     *
     * @return array{trees: array<int, string>, headers: array<int, array{tree: int, title: string, depth: int}>}
     */
    public function choices(): array;

    public function find(int $id): ?TreeRule;

    /**
     * Creates the rule when $id is null; otherwise replaces its settings when
     * the stored row still matches $revision. A change of an existing rule to
     * Device leaves removes its graph-based criteria, as legacy did, but only
     * when the change confirms it.
     *
     * @throws \Kadupul\Automation\Domain\TreeRuleRejected when a reference no longer matches or the removal is unconfirmed
     * @throws \InvalidArgumentException when the rule is missing or changed since $revision
     */
    public function save(int $actorId, ?int $id, TreeRuleChange $change, ?string $revision): int;
}
