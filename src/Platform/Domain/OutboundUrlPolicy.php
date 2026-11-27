<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain;

/**
 * Validates outbound HTTP(S) URLs and rejects loopback, link-local, private,
 * and cloud-metadata addresses unless private-network access is explicitly enabled.
 */
final readonly class OutboundUrlPolicy
{
    /** @var list<string> */
    private const array BLOCKED_HOSTNAMES = [
        'localhost',
        'localhost.localdomain',
        'ip6-localhost',
        'ip6-loopback',
        'metadata.google.internal',
        'metadata.goog',
    ];

    /** @var list<string> */
    private const array BLOCKED_SUFFIXES = [
        '.localhost',
        '.local',
        '.internal',
    ];

    public function assertAllowed(string $url, bool $allowPrivateNetwork = false): void
    {
        if ($url === '' || preg_match('/[\x00-\x1F\x7F\s]/', $url) === 1) {
            throw new \InvalidArgumentException('Outbound URL must not be empty or contain whitespace or control characters.');
        }

        $parts = parse_url($url);
        if ($parts === false) {
            throw new \InvalidArgumentException('Outbound URL is malformed.');
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException(sprintf('Outbound URL scheme "%s" is not permitted.', $scheme));
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Outbound URL must not embed credentials.');
        }

        $host = isset($parts['host']) ? strtolower(trim($parts['host'], '[]')) : '';
        if ($host === '') {
            throw new \InvalidArgumentException('Outbound URL must include a host.');
        }

        if ($allowPrivateNetwork) {
            return;
        }

        $normalizedHost = rtrim($host, '.');
        if (in_array($normalizedHost, self::BLOCKED_HOSTNAMES, true)) {
            throw new \InvalidArgumentException(sprintf('Outbound host "%s" is not permitted.', $host));
        }

        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if (str_ends_with($normalizedHost, $suffix)) {
                throw new \InvalidArgumentException(sprintf('Outbound host "%s" is not permitted.', $host));
            }
        }

        if (filter_var($normalizedHost, FILTER_VALIDATE_IP) !== false) {
            $this->assertIpAllowed($normalizedHost, false);
        }
    }

    public function assertIpAllowed(string $ip, bool $allowPrivateNetwork = false): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException(sprintf('Invalid IP address "%s".', $ip));
        }

        if ($allowPrivateNetwork) {
            return;
        }

        $packed = @inet_pton($ip);
        if (!is_string($packed)) {
            throw new \InvalidArgumentException(sprintf('Invalid IP address "%s".', $ip));
        }

        // Unwrap IPv4-mapped IPv6 addresses (::ffff:0:0/96) and check as IPv4.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
            $mapped = inet_ntop(substr($packed, 12, 4));
            if (is_string($mapped)) {
                $this->assertIpAllowed($mapped, false);

                return;
            }
        }

        // Reject RFC 6598 CGNAT (100.64.0.0/10) and standard private/reserved ranges.
        if (strlen($packed) === 4) {
            $first = ord($packed[0]);
            $second = ord($packed[1]);
            if ($first === 100 && ($second & 0xC0) === 64) {
                throw new \InvalidArgumentException(sprintf('Outbound IP "%s" is in a blocked address range.', $ip));
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new \InvalidArgumentException(sprintf('Outbound IP "%s" is in a blocked address range.', $ip));
        }
    }
}
