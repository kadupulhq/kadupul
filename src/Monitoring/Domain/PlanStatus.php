<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/** The lifecycle of a collection plan: proposed, approved, active, then superseded. */
enum PlanStatus: string
{
    case Proposed = 'proposed';
    case Approved = 'approved';
    case Active = 'active';
    case Superseded = 'superseded';

    /** Whether a plan in this status may move to $next; superseded is final. */
    public function canMoveTo(self $next): bool
    {
        return match ($this) {
            self::Proposed => $next === self::Approved,
            self::Approved => $next === self::Active || $next === self::Superseded,
            self::Active => $next === self::Superseded,
            self::Superseded => false,
        };
    }
}
