<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain;

/**
 * Pure domain policy validating internal redirect targets without post-check
 * URL decoding. Rejects external hosts, schemes, control characters, and
 * path traversal even when multi-encoded.
 */
final readonly class RedirectTarget
{
    private const int MAX_DECODE_PASSES = 10;

    /** @var list<string> */
    private const array BLOCKED_SCHEMES = [
        'javascript:',
        'data:',
        'vbscript:',
        'mailto:',
        'file:',
    ];

    public static function validate(
        string $candidate,
        string $default,
        ?string $serverName = null,
        ?string $httpHost = null,
        ?string $serverPort = null,
    ): string {
        if ($candidate === '') {
            return $default;
        }

        $url = self::decodeBounded(trim($candidate));
        if ($url === null) {
            return $default;
        }

        $url = str_replace('\\', '/', $url);

        if (preg_match('/[\x00-\x1f\x7f]/', $url) === 1) {
            return $default;
        }

        foreach (self::BLOCKED_SCHEMES as $scheme) {
            if (stripos($url, $scheme) !== false) {
                return $default;
            }
        }

        if (str_starts_with($url, '//') || str_contains($url, '..')) {
            return $default;
        }

        $parsed = parse_url($url);
        if ($parsed === false) {
            return $default;
        }

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            return $default;
        }

        $refScheme = isset($parsed['scheme']) ? strtolower($parsed['scheme']) : null;
        $refHost = $parsed['host'] ?? null;
        $refPort = $parsed['port'] ?? null;

        if ($refScheme !== null && !in_array($refScheme, ['http', 'https'], true)) {
            return $default;
        }

        if ($refScheme !== null && $refHost === null) {
            return $default;
        }

        $serverHost = self::normalizeServerHost($serverName, $httpHost);
        if ($refHost !== null) {
            if ($serverHost === null || strtolower($refHost) !== strtolower($serverHost)) {
                return $default;
            }
        }

        if ($refPort !== null) {
            if ($serverPort === null || $serverPort === '' || (string) $refPort !== $serverPort) {
                return $default;
            }
        }

        $refPath = $parsed['path'] ?? '';
        $refQuery = $parsed['query'] ?? null;

        if ($refPath !== '') {
            if (str_starts_with($refPath, '//')) {
                return $default;
            }

            if ($refPath[0] !== '/' && str_contains($refPath, ':')) {
                return $default;
            }
        }

        $relative = $refPath . ($refQuery !== null ? '?' . $refQuery : '');

        if (!self::isSafePostSanitization($relative)) {
            return $default;
        }

        return $relative;
    }

    public static function isSafePostSanitization(string $uri): bool
    {
        if ($uri === '' || str_starts_with($uri, '//') || str_contains($uri, '\\')) {
            return false;
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $uri) === 1) {
            return false;
        }

        if (preg_match('/\A[a-z][a-z0-9+.-]*:/i', $uri) === 1) {
            return false;
        }

        return !str_contains($uri, '..');
    }

    private static function decodeBounded(string $value): ?string
    {
        $current = $value;
        for ($pass = 0; $pass < self::MAX_DECODE_PASSES; $pass++) {
            $decoded = urldecode($current);
            if ($decoded === $current) {
                return $current;
            }
            $current = $decoded;
        }

        // Still changing after MAX_DECODE_PASSES indicates nested-encoding evasion.
        return urldecode($current) === $current ? $current : null;
    }

    private static function normalizeServerHost(?string $serverName, ?string $httpHost): ?string
    {
        if ($serverName !== null && $serverName !== '') {
            return preg_replace('/:\d+$/', '', $serverName);
        }

        if ($httpHost !== null && $httpHost !== '') {
            return preg_replace('/:\d+$/', '', $httpHost);
        }

        return null;
    }
}
