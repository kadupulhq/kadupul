<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Application\Port;

/** Write access to VDEFs. Every change names the revision the caller read, and a stale one is refused. */
interface VdefEditor
{
    /**
     * Creates a VDEF when $id is 0, otherwise renames it, and returns its id.
     *
     * @throws \InvalidArgumentException when the name is invalid, the VDEF is missing or it changed since $revision
     * @throws \Kadupul\GraphDefinition\Application\Query\VdefAccessDenied when the actor may not change VDEFs
     */
    public function save(int $actorId, int $id, string $name, string $revision = ''): int;

    /**
     * Adds an item when $itemId is 0, otherwise changes that item.
     *
     * @throws \InvalidArgumentException when the item is invalid or missing, or the VDEF changed since $revision
     * @throws \Kadupul\GraphDefinition\Application\Query\VdefAccessDenied when the actor may not change VDEFs
     */
    public function saveItem(int $actorId, int $vdefId, int $itemId, int $type, string $value, string $revision): void;

    /**
     * Removes one item from a VDEF.
     *
     * @throws \InvalidArgumentException when the item is missing or the VDEF changed since $revision
     * @throws \Kadupul\GraphDefinition\Application\Query\VdefAccessDenied when the actor may not change VDEFs
     */
    public function deleteItem(int $actorId, int $vdefId, int $itemId, string $revision): void;

    /** @param list<int> $orderedItemIds */
    public function reorder(int $actorId, int $vdefId, array $orderedItemIds, string $revision): void;

    /** @param list<int> $ids */
    public function act(int $actorId, string $action, array $ids, string $titleFormat = '<vdef_title> (1)', array $revisions = []): void;
}
