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
    /** A bulk selection travels in the confirmation URL, so it stays well under common URL limits. */
    public const int MAX_SELECTION = 100;

    /**
     * Trees by ID ordered by name, and each tree's headers in tree order with their depth.
     *
     * @return array{trees: array<int, string>, headers: array<int, array{tree: int, title: string, depth: int}>}
     */
    public function choices(): array;

    public function find(int $id): ?TreeRule;

    /**
     * @param list<int> $ids
     * @return array<int, TreeRule> keyed by ID in ID order, only for IDs that exist
     */
    public function findRules(array $ids): array;

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

    /**
     * Delete the rules with their Tree Creation Criteria and Object Selection
     * Criteria. Match items of other rule types are left alone.
     *
     * @param list<int> $ids ascending and distinct
     * @param array<int, string> $revisions the revision shown for each selected rule
     */
    public function deleteRules(int $actorId, array $ids, array $revisions): void;

    /**
     * Copy the rules with both kinds of criteria. Each copy is named by
     * TreeRule::copyName() and starts disabled, as on the legacy page.
     *
     * @param list<int> $ids ascending and distinct
     * @param array<int, string> $revisions the revision shown for each selected rule
     */
    public function duplicateRules(int $actorId, array $ids, array $revisions, string $nameFormat): void;

    /**
     * @param list<int> $ids ascending and distinct
     * @param array<int, string> $revisions the revision shown for each selected rule
     */
    public function setEnabled(int $actorId, array $ids, array $revisions, bool $enabled): void;
}
