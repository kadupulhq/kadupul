<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Domain\Rule;

/** Which side of an hour-of-week band breaches. Stored as these names in alert_rule.baseline_direction. */
enum BaselineDirection: string
{
    case Above = 'above';
    case Below = 'below';
    case Both = 'both';

    public function watchesAbove(): bool
    {
        return $this !== self::Below;
    }

    public function watchesBelow(): bool
    {
        return $this !== self::Above;
    }
}
