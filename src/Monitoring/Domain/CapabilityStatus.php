<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

enum CapabilityStatus: string
{
    case Advertised = 'advertised';
    case Verified = 'verified';
    case Partial = 'partial';
    case Unsupported = 'unsupported';
    case Unknown = 'unknown';

    /**
     * Verified, partial and unsupported come from reading the model on the
     * device. A later claim or a failed look carries less information, so it
     * never replaces one of these.
     */
    public function isVerificationOutcome(): bool
    {
        return in_array($this, [self::Verified, self::Partial, self::Unsupported], true);
    }
}
