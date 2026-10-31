<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

/** A graph tree as the --list-trees mode of add_tree.php prints it. */
final readonly class TreeSummary
{
    public function __construct(public int $id, public int $sortType, public string $name) {}
}
