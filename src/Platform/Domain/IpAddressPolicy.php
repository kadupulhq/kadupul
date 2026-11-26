<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain;

/**
 * Strict IP address and remote-target host policy enforcing valid IPv6-only
 * zone identifiers and rejecting loopback, link-local, unspecified, reserved,
 * and filesystem socket paths for remote collector endpoints.
 */
final readonly class IpAddressPolicy
{
    private const string ZONE_ID_PATTERN = '/\A[A-Za-z0-9._-]{1,64}\z/D';

    public static function isValidIpAddress(string $address): bool
    {
        if ($address === '' || preg_match('/[\x00-\x1f\x7f\s]/', $address) === 1) {
            return false;
        }

        $candidate = $address;
        if (str_starts_with($candidate, '[') && str_ends_with($candidate, ']')) {
            $candidate = substr($candidate, 1, -1);
            if ($candidate === '' || str_contains($candidate, '[') || str_contains($candidate, ']')) {
                return false;
            }
        }

        if (str_contains($candidate, '%')) {
            $parts = explode('%', $candidate, 2);
            $ipPart = $parts[0];
            $zoneId = $parts[1] ?? '';

            if (preg_match(self::ZONE_ID_PATTERN, $zoneId) !== 1) {
                return false;
            }

            // Zone indices are defined strictly for IPv6 addresses, never IPv4.
            return filter_var($ipPart, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        return filter_var($candidate, FILTER_VALIDATE_IP) !== false;
    }

    public static function isLoopbackOrReservedIp(string $ip): bool
    {
        $candidate = trim($ip);
        if (str_starts_with($candidate, '[') && str_ends_with($candidate, ']')) {
            $candidate = substr($candidate, 1, -1);
        }
        if (str_contains($candidate, '%')) {
            $candidate = explode('%', $candidate, 2)[0];
        }

        if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        $packed = @inet_pton($candidate);
        if ($packed === false) {
            return true;
        }

        if (strlen($packed) === 4) {
            $first = ord($packed[0]);
            $second = ord($packed[1]);
            // 0.0.0.0/8, 127.0.0.0/8, 169.254.0.0/16, 224.0.0.0/4+
            return $first === 0
                || $first === 127
                || ($first === 169 && $second === 254)
                || $first >= 224;
        }

        // IPv6 ::, ::1, fe80::/10, ff00::/8, and IPv4-mapped loopback/link-local/unspecified
        $raw = unpack('C16', $packed);
        if ($raw === false || count($raw) !== 16) {
            return true;
        }
        /** @var list<int> $unpacked */
        $unpacked = array_values(array_map(static fn(mixed $byte): int => is_int($byte) ? $byte : 0, $raw));

        $allZero = true;
        for ($i = 0; $i < 15; $i++) {
            if ($unpacked[$i] !== 0) {
                $allZero = false;
                break;
            }
        }
        if ($allZero && ($unpacked[15] === 0 || $unpacked[15] === 1)) {
            return true;
        }

        // Link-local fe80::/10 or multicast ff00::/8
        if (($unpacked[0] === 0xfe && ($unpacked[1] & 0xc0) === 0x80) || $unpacked[0] === 0xff) {
            return true;
        }

        // IPv4-mapped ::ffff:a.b.c.d
        $isV4Mapped = true;
        for ($i = 0; $i < 10; $i++) {
            if ($unpacked[$i] !== 0) {
                $isV4Mapped = false;
                break;
            }
        }
        if ($isV4Mapped && $unpacked[10] === 0xff && $unpacked[11] === 0xff) {
            $v4 = sprintf('%d.%d.%d.%d', $unpacked[12], $unpacked[13], $unpacked[14], $unpacked[15]);

            return self::isLoopbackOrReservedIp($v4);
        }

        return false;
    }

    public static function isSafeRemoteHostSyntax(string $host): bool
    {
        $trimmed = trim($host);
        if ($trimmed === '' || str_starts_with($trimmed, '-') || str_contains($trimmed, '/') || str_contains($trimmed, '\\')) {
            return false;
        }

        if (preg_match('/[\x00-\x1f\x7f\s;]/', $trimmed) === 1) {
            return false;
        }

        $lower = strtolower(rtrim($trimmed, '.'));
        if ($lower === 'localhost' || str_ends_with($lower, '.localhost')) {
            return false;
        }

        if (self::isValidIpAddress($trimmed)) {
            return !self::isLoopbackOrReservedIp($trimmed);
        }

        return filter_var($trimmed, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
