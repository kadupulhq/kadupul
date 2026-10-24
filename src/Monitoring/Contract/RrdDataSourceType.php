<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Contract;

/** The data-source type a binding expects; mirrors the Monitoring domain enum. */
enum RrdDataSourceType: string
{
    case Gauge = 'GAUGE';
    case Counter = 'COUNTER';
    case Derive = 'DERIVE';
    case Absolute = 'ABSOLUTE';
}
