<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

enum PlanStatus: string
{
    case Proposed = 'proposed';
    case Approved = 'approved';
    case Active = 'active';
    case Superseded = 'superseded';

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
