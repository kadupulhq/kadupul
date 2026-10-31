<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

/** The fields api_tree_item_save() takes for a new node; the ones that do not apply to its kind are zero or empty. */
final readonly class TreeNodeDraft
{
    public function __construct(
        public int $treeId,
        public int $itemType,
        public int $parentId,
        public string $title,
        public int $graphId,
        public int $deviceId,
        public int $siteId,
        public int $groupingType,
        public int $sortType,
    ) {}
}
