<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/** Private process-pipe evidence. Never render or log this snapshot or its proof.
 * @phpstan-type RuntimeSnapshot array{php: string, version_id: int, extensions: array<string, string|null>, uid: int|null, gid: int|null, directories: array<string, array{present: bool, readable: bool, writable: bool, traversable: bool|null, location: string|null}>}
 */
final class InstallerRuntimeSnapshot
{
    public const DIRECTORIES = ['log', 'cache', 'cache/boost', 'cache/mibcache', 'cache/realtime', 'cache/spikekill',
        'var', 'rra', 'scripts', 'resource/snmp_queries', 'resource/script_server', 'resource/script_queries'];

    /** @return RuntimeSnapshot */
    public static function capture(string $projectDir): array
    {
        $source = @file_get_contents($projectDir . '/composer.json', false, null, 0, 65537);
        if ($source === false || strlen($source) > 65536) {
            throw new \RuntimeException('Runtime manifest unavailable.');
        }
        $manifest = json_decode($source, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || !is_array($manifest['require'] ?? null)) {
            throw new \RuntimeException('Runtime manifest unavailable.');
        }
        $extensions = [];
        foreach (array_keys($manifest['require']) as $package) {
            if (is_string($package) && preg_match('/\Aext-([a-zA-Z0-9_]+)\z/D', $package, $match) === 1) {
                $name = strtolower($match[1]);
                $version = phpversion($name);
                // mysqlnd reports "mysqlnd 8.4.x" rather than just its version.
                // The extension identity is already the map key.
                if ($name === 'mysqlnd' && is_string($version) && str_starts_with($version, 'mysqlnd ')) {
                    $version = substr($version, 8);
                }
                $extensions[$name] = extension_loaded($name) ? ($version !== false && $version !== '' ? $version : 'loaded') : null;
            }
        }
        ksort($extensions);
        $directories = [];
        foreach (self::DIRECTORIES as $relative) {
            $directories[$relative] = self::directory($projectDir . '/' . $relative);
        }
        $directories['temporary'] = self::directory(sys_get_temp_dir());
        return ['php' => PHP_VERSION, 'version_id' => PHP_VERSION_ID, 'extensions' => $extensions,
            'uid' => function_exists('posix_geteuid') ? posix_geteuid() : null,
            'gid' => function_exists('posix_getegid') ? posix_getegid() : null, 'directories' => $directories];
    }

    /** @param array<string, mixed> $snapshot */
    public static function proof(#[\SensitiveParameter] array $snapshot, string $nonce, #[\SensitiveParameter] string $secret): ?string
    {
        return strlen($secret) >= 32
            ? hash_hmac('sha256', "installer-runtime-v1\0" . $nonce . "\0" . json_encode($snapshot, JSON_THROW_ON_ERROR), $secret)
            : null;
    }

    /** @return array{present: bool, readable: bool, writable: bool, traversable: bool|null, location: string|null} */
    private static function directory(string $path): array
    {
        $present = is_dir($path);
        $location = $present ? realpath($path) : false;
        return ['present' => $present, 'readable' => $present && is_readable($path),
            'writable' => $present && is_writable($path),
            'traversable' => PHP_OS_FAMILY === 'Windows' ? null : $present && is_executable($path),
            'location' => $location !== false ? $location : null];
    }
}
