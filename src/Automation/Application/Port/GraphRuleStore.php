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
    /** A bulk selection travels in the confirmation URL, so it stays well under common URL limits. */
    public const int MAX_SELECTION = 100;

    /**
     * Data Queries by ID, and each Graph Type with its Data Query, ordered by name.
     *
     * @return array{queries: array<int, string>, graphTypes: array<int, array{query: int, name: string}>}
     */
    public function choices(): array;

    public function find(int $id): ?GraphRule;

    /**
     * @param list<int> $ids
     * @return array<int, GraphRule> keyed by ID in ID order, only for IDs that exist
     */
    public function findRules(array $ids): array;

    /**
     * Creates the rule when $id is null; otherwise replaces its settings when
     * the stored row still matches $revision. Criteria items are not changed.
     *
     * @throws \Kadupul\Automation\Domain\GraphRuleRejected when the Data Query or Graph Type no longer matches
     * @throws \InvalidArgumentException when the rule is missing or changed since $revision
     */
    public function save(int $actorId, ?int $id, GraphRuleChange $change, ?string $revision): int;

    /**
     * Delete the rules with their Graph Creation Criteria and Device Selection
     * Criteria. Match items of other rule types are left alone.
     *
     * @param list<int> $ids ascending and distinct
     * @param array<int, string> $revisions the revision shown for each selected rule
     */
    public function deleteRules(int $actorId, array $ids, array $revisions): void;

    /**
     * Copy the rules with both kinds of criteria. Each copy is named by
     * GraphRule::copyName() and starts disabled, as on the legacy page.
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
