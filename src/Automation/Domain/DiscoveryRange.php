<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Domain;

/**
 * The address count poller_automation.php scans for one discovery range.
 *
 * It follows automation_get_network_info() in lib/api_automation.php for the
 * spellings that function handles consistently: a single IPv4 address, an
 * address with a prefix length or a dotted netmask, and a wildcard such as
 * 10.1.*.*. Subnets exclude their network and broadcast addresses.
 */
final class DiscoveryRange
{
    /** The total_ips column is an unsigned 32-bit integer. */
    public const int TOTAL_MAX = 4294967295;

    /** The number of addresses a range covers, or null when the range is not one legacy discovery can scan. */
    public static function addresses(string $range): ?int
    {
        $range = trim($range);
        if ($range === '' || str_contains($range, '-')) {
            // Legacy rejects range1-range2 spellings explicitly.
            return null;
        }
        if (str_contains($range, '/')) {
            $parts = explode('/', $range);
            if (count($parts) !== 2 || !self::isIpv4($parts[0])) {
                return null;
            }
            $prefix = self::prefixLength($parts[1]);
            return $prefix === null ? null : self::subnetSize($prefix);
        }
        if (str_contains($range, '*')) {
            return self::wildcard($range);
        }
        return ip2long($range) === false ? null : 1;
    }

    /**
     * The total for a comma-separated subnet_range value.
     *
     * @return array{int, null}|array{null, string} the total, or the first range that is invalid
     */
    public static function total(string $ranges): array
    {
        $total = 0;
        foreach (explode(',', $ranges) as $range) {
            $count = self::addresses($range);
            if ($count === null) {
                return [null, trim($range)];
            }
            $total += $count;
        }
        return [$total, null];
    }

    private static function isIpv4(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && ip2long($address) !== false;
    }

    private static function prefixLength(string $mask): ?int
    {
        if (preg_match('/\A[0-9]{1,2}\z/', $mask) === 1) {
            $prefix = (int) $mask;
            return $prefix >= 1 && $prefix <= 32 ? $prefix : null;
        }
        $long = ip2long($mask);
        // A netmask has its top bit set and no one bit after its first zero bit.
        if ($long === false || ($long & 0x80000000) === 0) {
            return null;
        }
        $bits = sprintf('%032b', $long);
        return preg_match('/\A1+0*\z/', $bits) === 1 ? substr_count($bits, '1') : null;
    }

    private static function subnetSize(int $prefix): int
    {
        // A /32 is one address; otherwise the network and broadcast addresses are not scanned.
        return $prefix === 32 ? 1 : (2 ** (32 - $prefix)) - 2;
    }

    private static function wildcard(string $range): ?int
    {
        if (!self::isIpv4(str_replace('*', '0', $range))) {
            return null;
        }
        // The octets before the first * select the network; anything after it is ignored.
        foreach (explode('.', $range) as $index => $octet) {
            if (str_contains($octet, '*')) {
                return $octet === '*' && $index >= 1 && $index <= 3 ? self::subnetSize(8 * $index) : null;
            }
        }
        return null;
    }
}
