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
    /** Practical per-network address cap (two /16 subnets) to prevent unbounded discovery IP priming. */
    public const int TOTAL_MAX = 131072;

    /** @var list<array{int, int}> Blocked IPv4 target intervals (unspecified, loopback, link-local/metadata, multicast, reserved). */
    private const array BLOCKED_IPV4_RANGES = [
        [0x00000000, 0x00FFFFFF],
        [0x7F000000, 0x7FFFFFFF],
        [0xA9FE0000, 0xA9FEFFFF],
        [0xE0000000, 0xEFFFFFFF],
        [0xF0000000, 0xFFFFFFFF],
    ];

    /** The number of addresses a range covers, or null when the range is invalid, disallowed, or exceeds TOTAL_MAX. */
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
            if ($prefix === null) {
                return null;
            }
            $count = self::subnetSize($prefix);
            if ($count > self::TOTAL_MAX) {
                return null;
            }
            $ipLong = ip2long($parts[0]);
            if ($ipLong === false) {
                return null;
            }
            $mask = $prefix === 32 ? 0xFFFFFFFF : ((0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF);
            $network = $ipLong & $mask;
            $broadcast = $network | (~$mask & 0xFFFFFFFF);
            return self::isAllowedIpv4Range($network, $broadcast) ? $count : null;
        }
        if (str_contains($range, '*')) {
            return self::wildcard($range);
        }
        if (!self::isIpv4($range)) {
            return null;
        }
        $long = ip2long($range);
        return $long !== false && self::isAllowedIpv4Range($long, $long) ? 1 : null;
    }

    /**
     * The total for a comma-separated subnet_range value.
     *
     * @return array{int, null}|array{null, string} the total, or the first range that is invalid or exceeds TOTAL_MAX
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
            if ($total > self::TOTAL_MAX) {
                return [null, trim($range)];
            }
        }
        return [$total, null];
    }

    /**
     * Whether a bare IPv4 or IPv6 address is permitted as a discovery target or DNS server.
     *
     * Private ranges stay allowed for internal discovery, while unspecified, loopback,
     * link-local/cloud-metadata, multicast, and reserved ranges are refused.
     */
    public static function isAllowedAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 16) {
            if ($packed === str_repeat("\0", 15) . "\1" || ord($packed[0]) === 0xFF) {
                return false;
            }
            if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")
                || str_starts_with($packed, "\x00\x64\xff\x9b" . str_repeat("\0", 8))
                || (str_starts_with($packed, str_repeat("\0", 12)) && ltrim(substr($packed, 12), "\0") !== '')) {
                $packed = substr($packed, 12);
            } elseif (str_starts_with($packed, "\x20\x02")) {
                $packed = substr($packed, 2, 4);
            } else {
                return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_RES_RANGE) !== false;
            }
        }
        $unpacked = unpack('N', $packed);
        return $unpacked !== false && self::isAllowedIpv4Range($unpacked[1], $unpacked[1]);
    }

    private static function isAllowedIpv4Range(int $start, int $end): bool
    {
        if ($start < 0 || $end < $start || $end > 0xFFFFFFFF) {
            return false;
        }
        foreach (self::BLOCKED_IPV4_RANGES as [$blockedStart, $blockedEnd]) {
            if ($start <= $blockedEnd && $blockedStart <= $end) {
                return false;
            }
        }
        return filter_var(long2ip($start), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_RES_RANGE) !== false
            && filter_var(long2ip($end), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_RES_RANGE) !== false;
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
        $base = str_replace('*', '0', $range);
        if (!self::isIpv4($base)) {
            return null;
        }
        // The octets before the first * select the network; anything after it is ignored.
        foreach (explode('.', $range) as $index => $octet) {
            if (str_contains($octet, '*')) {
                if ($octet !== '*' || $index < 1 || $index > 3) {
                    return null;
                }
                $prefix = 8 * $index;
                $count = self::subnetSize($prefix);
                if ($count > self::TOTAL_MAX) {
                    return null;
                }
                $baseLong = ip2long($base);
                if ($baseLong === false) {
                    return null;
                }
                $mask = (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
                $network = $baseLong & $mask;
                $broadcast = $network | (~$mask & 0xFFFFFFFF);
                return self::isAllowedIpv4Range($network, $broadcast) ? $count : null;
            }
        }
        return null;
    }
}
