<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Domain;

enum AssociationState: string
{
    case Unknown = 'unknown';
    case Advertised = 'advertised';
    case Verified = 'verified';
    case Partial = 'partial';
    case Unsupported = 'unsupported';

    /** Unknown is only an initial state: once a device has been observed it never reverts to it. */
    public function mayBecome(self $next): bool
    {
        return match ($next) {
            self::Unknown => false,
            self::Advertised => $this === self::Unknown || $this === self::Advertised,
            self::Verified, self::Partial, self::Unsupported => true,
        };
    }

    /** Only a verification record can establish these states. */
    public function needsVerification(): bool
    {
        return $this === self::Verified || $this === self::Partial;
    }
}
