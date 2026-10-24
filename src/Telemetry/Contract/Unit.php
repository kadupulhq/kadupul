<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Contract;

enum Unit: string
{
    case Count = 'count';
    case Octets = 'octets';
    case Packets = 'packets';
    case Seconds = 'seconds';
}
