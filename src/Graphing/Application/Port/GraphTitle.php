<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

/** One graph as the rename tools list it: its local id and the title stored for it. */
final readonly class GraphTitle
{
    public function __construct(public int $localGraphId, public string $title) {}
}
