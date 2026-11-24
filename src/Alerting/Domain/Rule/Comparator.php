<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Domain\Rule;

/** Stored as the lower-case names used in the alert_rule table. */
enum Comparator: string
{
    case GreaterThan = 'gt';
    case GreaterOrEqual = 'ge';
    case LessThan = 'lt';
    case LessOrEqual = 'le';

    /** True when the breaching side is above the threshold. */
    public function breachesUpward(): bool
    {
        return $this === self::GreaterThan || $this === self::GreaterOrEqual;
    }
}
