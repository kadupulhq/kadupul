<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

final readonly class DataQueryReorderResult
{
    /**
     * @param list<string> $indexOn the field each finished item was indexed on, in order
     * @param bool $unresolved the item after the finished ones had no sort field, so the run stopped there
     */
    public function __construct(public array $indexOn, public bool $unresolved) {}
}
