<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\GraphDefinition\Application\Port;

interface CdefEditor
{
    public function save(int $actorId, int $id, string $name): int;

    public function saveItem(int $actorId, int $cdefId, int $itemId, int $type, string $value): void;

    public function deleteItem(int $actorId, int $cdefId, int $itemId): void;

    /** @param list<int> $orderedItemIds @param list<int> $expectedItemIds */
    public function reorder(int $actorId, int $cdefId, array $orderedItemIds, array $expectedItemIds): void;

    /** @param list<int> $ids */
    public function act(int $actorId, string $action, array $ids, string $titleFormat = '<cdef_title> (1)'): void;
}
