<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Application;

enum IntervalKind: string
{
    case Delta = 'delta';
    case Reset = 'reset';
    case Gap = 'gap';
}
