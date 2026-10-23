<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

final readonly class PlanningPolicy
{
    private function __construct(public bool $acceptAdvertised, public bool $preferNew) {}

    /** The pack schema's reading: only capabilities read back from the device count. */
    public static function verifiedOnly(): self
    {
        return new self(false, false);
    }

    /** Trust what a device claims, for devices nothing has verified yet. */
    public static function advertisedOrVerified(): self
    {
        return new self(true, false);
    }

    /** Plan a series even when something else already collects it; the overlap is still reported. */
    public function preferringNew(): self
    {
        return new self($this->acceptAdvertised, true);
    }

    public function counts(Capability $capability): bool
    {
        return $capability->status === CapabilityStatus::Verified
            || ($this->acceptAdvertised && $capability->status === CapabilityStatus::Advertised);
    }
}
