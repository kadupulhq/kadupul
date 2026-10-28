<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Contract;

/**
 * Decide which addresses a request to a remote data collector may reach.
 *
 * Collectors normally run on internal networks, so private ranges stay
 * allowed, and loopback stays allowed for a main and remote collector on one
 * host or in host-network containers. Link-local addresses, including the
 * 169.254.169.254 cloud metadata address, 0.0.0.0/8 and the other reserved
 * ranges are refused.
 */
final class CollectorAddress
{
    private const DNS_NAME = '/^(?=.{1,253}\.?$)[A-Za-z0-9_](?:[A-Za-z0-9_-]{0,61}[A-Za-z0-9_])?(?:\.[A-Za-z0-9_](?:[A-Za-z0-9_-]{0,61}[A-Za-z0-9_])?)*\.?$/';

    /**
     * Return the stored collector hostname as a bare IP address or DNS name,
     * or null when it is neither.
     *
     * A bracketed IPv6 literal loses its brackets. The name becomes the Host
     * header and TLS peer name of the request, and the poller form saves it
     * without a pattern, so control characters and spaces are refused. Names
     * may use underscores, as container and internal DNS names often do.
     */
    public static function host(mixed $hostname): ?string
    {
        if (!is_string($hostname) || $hostname === '') {
            return null;
        }

        if (preg_match('/^\[([^\]]+)\]$/', $hostname, $bracketed) === 1 && filter_var($bracketed[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $hostname = $bracketed[1];
        }

        if (preg_match('/[\x00-\x20\x7f]/', $hostname) === 1) {
            return null;
        }

        if (filter_var($hostname, FILTER_VALIDATE_IP) !== false || preg_match(self::DNS_NAME, $hostname) === 1) {
            return $hostname;
        }

        return null;
    }

    /**
     * Whether a request may connect to $address. An IPv6 address that carries
     * an IPv4 address (IPv4-mapped, IPv4-compatible, NAT64 or 6to4) is judged
     * as the IPv4 address a translating gateway would reach.
     */
    public static function allows(string $address): bool
    {
        $packed = @inet_pton($address);

        if ($packed === false || filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (strlen($packed) === 16) {
            if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")
                || str_starts_with($packed, "\x00\x64\xff\x9b" . str_repeat("\0", 8))
                || (str_starts_with($packed, str_repeat("\0", 12)) && ltrim(substr($packed, 12), "\0") !== '' && substr($packed, 12) !== "\0\0\0\1")) {
                $packed = substr($packed, 12);
            } elseif (str_starts_with($packed, "\x20\x02")) {
                $packed = substr($packed, 2, 4);
            }
        }

        if ((strlen($packed) === 4 && ord($packed[0]) === 127) || $packed === str_repeat("\0", 15) . "\1") {
            return true;
        }

        $checked = inet_ntop($packed);

        return $checked !== false && filter_var($checked, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * Resolve a host returned by host() once and return the address to connect
     * to, or null when it does not resolve or may not be reached.
     *
     * @param callable(string): mixed $lookup Name resolver, gethostbyname() in production.
     */
    public static function connectAddress(string $host, callable $lookup): ?string
    {
        $address = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : $lookup($host);

        return is_string($address) && self::allows($address) ? $address : null;
    }

    /** Write an address or name as a URL host; an IPv6 literal needs brackets. */
    public static function urlHost(string $host): string
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $host . ']' : $host;
    }
}
