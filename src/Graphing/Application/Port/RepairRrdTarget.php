<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

/** A data source and its first RRD item; $rrdId is null for a data source with none. */
final readonly class RepairRrdTarget
{
    public function __construct(public int $dataSourceId, public ?int $rrdId) {}
}
