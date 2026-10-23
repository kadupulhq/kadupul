<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Contract;

enum CounterWidth: int
{
    case Bits32 = 32;
    case Bits64 = 64;

    public function maximum(): CounterValue
    {
        return CounterValue::fromString(gmp_strval(gmp_sub(gmp_pow(2, $this->value), 1)));
    }
}
