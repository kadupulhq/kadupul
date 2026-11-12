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

    /** The configured list page size. */
    public function defaultRows(): int;

    /** Whether the list should start with only CDEFs used by graphs. */
    public function defaultHasGraphs(): bool;

    /** One filtered, sorted page of user CDEFs with their usage counts. */
    public function list(CdefFilters $filters): CdefPage;

    /** The user CDEF with its items and expanded preview, or null when it does not exist or is a system CDEF. */
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

    /**
     * Creates a CDEF, or renames the one in $id, and returns its id.
     *
     * @param int|null $id null to create
     * @param string|null $revision the revision the form was opened with; required when updating
     * @throws \InvalidArgumentException when the name is invalid or the CDEF changed since $revision
     */
    public function save(int $actorId, ?int $id, string $name, ?string $revision): int;

    /**
     * Adds an item to a CDEF, or changes the item in $itemId.
     *
     * @param int|null $itemId null to append a new item
     * @param int $type one of the CdefFunctions types
     * @throws \InvalidArgumentException when the item is invalid, would make the CDEF include itself, or the CDEF changed since $revision
     */
    public function saveItem(int $actorId, int $cdefId, ?int $itemId, int $type, string $value, string $revision): void;

    /**
     * Removes one item from a CDEF.
     *
     * @throws \InvalidArgumentException when the item does not exist or the CDEF changed since $revision
     */
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
