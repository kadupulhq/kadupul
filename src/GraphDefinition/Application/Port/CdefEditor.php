<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Application\Port;

interface CdefEditor
{
    // Existing records require the full displayed revision. Empty defaults
    // reject stale legacy callers; creation has no existing record snapshot.
    public function save(int $actorId, int $id, string $name, string $expectedRevision = ''): int;

    public function saveItem(int $actorId, int $cdefId, int $itemId, int $type, string $value, string $expectedRevision = ''): void;

    public function deleteItem(int $actorId, int $cdefId, int $itemId, string $expectedRevision = ''): void;

    /** @param list<int> $orderedItemIds @param list<int> $expectedItemIds */
    public function reorder(int $actorId, int $cdefId, array $orderedItemIds, array $expectedItemIds, string $expectedRevision = ''): void;

    /** @param list<int> $ids @param array<int,string> $expectedRevisions */
    public function act(int $actorId, string $action, array $ids, string $titleFormat = '<cdef_title> (1)', array $expectedRevisions = []): void;
}
