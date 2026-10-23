<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

enum BindingStatus: string
{
    case Proposed = 'proposed';
    case Approved = 'approved';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Stale = 'stale';

    public function canMoveTo(self $next): bool
    {
        return match ($this) {
            self::Proposed => $next === self::Approved || $next === self::Rejected,
            self::Approved => $next === self::Verified || $next === self::Stale || $next === self::Rejected,
            self::Verified => $next === self::Stale,
            self::Stale => $next === self::Rejected,
            self::Rejected => false,
        };
    }

    /** Only an operator-approved binding may be used for storage. */
    public function isUsable(): bool
    {
        return $this === self::Approved || $this === self::Verified;
    }
}
