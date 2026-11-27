<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Domain;

/**
 * SNMP protocol versions matching $snmp_versions in include/global_arrays.php.
 */
enum SnmpVersion: int
{
    case None = 0;
    case V1 = 1;
    case V2c = 2;
    case V3 = 3;

    public function isEnabled(): bool
    {
        return $this !== self::None;
    }

    public function usesCommunityString(): bool
    {
        return $this === self::V1 || $this === self::V2c;
    }

    public function usesUsm(): bool
    {
        return $this === self::V3;
    }
}
