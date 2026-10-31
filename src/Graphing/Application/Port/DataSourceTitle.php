<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

/** One data source as the rename tool lists it: its local id and the title stored for it. */
final readonly class DataSourceTitle
{
    public function __construct(public int $localDataId, public string $title) {}
}
