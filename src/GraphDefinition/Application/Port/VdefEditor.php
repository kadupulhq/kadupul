<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Application\Port;

interface VdefEditor
{
    public function save(int $actorId, int $id, string $name, string $revision = ''): int;

    public function saveItem(int $actorId, int $vdefId, int $itemId, int $type, string $value, string $revision): void;

    public function deleteItem(int $actorId, int $vdefId, int $itemId, string $revision): void;

    /** @param list<int> $orderedItemIds */
    public function reorder(int $actorId, int $vdefId, array $orderedItemIds, string $revision): void;

    /** @param list<int> $ids */
    public function act(int $actorId, string $action, array $ids, string $titleFormat = '<vdef_title> (1)', array $revisions = []): void;
}
