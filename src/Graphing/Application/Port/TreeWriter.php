<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** Creates trees and tree nodes through the legacy code that owns branch sorting, locking and the change timestamp. */
interface TreeWriter
{
    /**
     * Saves the tree and sorts its root branch, as add_tree.php did.
     *
     * @phpstan-impure
     * @return int the new tree's id
     * @throws \RuntimeException when the tree was not saved or the outcome is unknown
     */
    public function createTree(OperatorDatabase $database, string $name, int $sortType): int;

    /**
     * @phpstan-impure
     * @return int the new node's id
     * @throws \RuntimeException when the node was not saved, for instance because the device, graph or site is already in that branch
     */
    public function createNode(OperatorDatabase $database, TreeNodeDraft $node): int;
}
