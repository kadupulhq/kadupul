<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Contract;

/**
 * Size in bits of a counter register; the value is the bit count.
 */
enum CounterWidth: int
{
    case Bits32 = 32;
    case Bits64 = 64;

    /**
     * Returns the largest reading a counter of this width can hold.
     */
    public function maximum(): CounterValue
    {
        return CounterValue::fromString(gmp_strval(gmp_sub(gmp_pow(2, $this->value), 1)));
    }
}
