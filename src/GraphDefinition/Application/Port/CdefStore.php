<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Application\Port;

use Kadupul\GraphDefinition\Domain\Cdef;
use Kadupul\GraphDefinition\Domain\CdefFilters;
use Kadupul\GraphDefinition\Domain\CdefPage;
use Kadupul\GraphDefinition\Domain\CdefSummary;

/**
 * Writes throw \InvalidArgumentException for a refusal the user can correct,
 * including a stale revision. Any other exception leaves the outcome uncertain.
 */
interface CdefStore
{
    public const int MAX_SELECTION = 100;

    public function defaultRows(): int;

    public function defaultHasGraphs(): bool;

    public function list(CdefFilters $filters): CdefPage;

    public function find(int $id): ?Cdef;

    /**
     * @param list<int> $ids
     * @return array<int, array{summary: CdefSummary, revision: string}> keyed by ID, only for IDs that exist
     */
    public function findMany(array $ids): array;

    /** @return array<string, string> function value => name for the configured RRDtool */
    public function functions(): array;

    /** @return array<string, string> CDEF ID => name, other user CDEFs that can be referenced */
    public function references(int $excludeId): array;

    public function save(int $actorId, ?int $id, string $name, ?string $revision): int;

    public function saveItem(int $actorId, int $cdefId, ?int $itemId, int $type, string $value, string $revision): void;

    public function deleteItem(int $actorId, int $cdefId, int $itemId, string $revision): void;

    /** Swap the item with its neighbour; $offset is -1 or 1. */
    public function moveItem(int $actorId, int $cdefId, int $itemId, int $offset, string $revision): void;

    /**
     * @param list<int> $ids
     * @param array<int, string> $revisions
     */
    public function duplicate(int $actorId, array $ids, array $revisions, string $titleFormat): void;

    /**
     * @param list<int> $ids
     * @param array<int, string> $revisions
     */
    public function delete(int $actorId, array $ids, array $revisions): void;
}
