<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

/**
 * Serializes cache:clear's directory swap against web requests using that cache.
 *
 * cache:clear renames the build directory aside and then renames the warmed
 * replacement into place. A booted web kernel loads container service files
 * lazily by absolute path, so a load between those renames throws "Failed
 * opening required". Web kernels hold the shared lock from boot to shutdown;
 * cache:clear holds the exclusive lock while it runs.
 */
final class CacheDirectoryLock
{
    /** Readers outwait the installer's 120-second refresh process budget. */
    public const SHARED_TIMEOUT_SECONDS = 150.0;
    public const EXCLUSIVE_TIMEOUT_SECONDS = 30.0;
    private const PAUSE_MICROSECONDS = 20000;

    /** @var resource|null */
    private $handle;

    /** @param resource $handle */
    private function __construct($handle)
    {
        $this->handle = $handle;
    }

    /** A sibling file: cache:clear renames and removes the directory itself. */
    public static function path(string $buildDir): string
    {
        return rtrim($buildDir, '/\\') . '.lock';
    }

    /**
     * Returns null when no lock can be held. A cache whose lock file cannot be
     * created is not writable for a refresh either, and a reader that outwaits
     * a stuck refresh keeps the previous unserialized behavior.
     */
    public static function shared(string $buildDir, float $timeout = self::SHARED_TIMEOUT_SECONDS): ?self
    {
        $handle = self::open($buildDir);
        if ($handle === null) {
            return null;
        }
        if (!self::lock($handle, LOCK_SH, $timeout)) {
            fclose($handle);

            return null;
        }

        return new self($handle);
    }

    /** @throws \RuntimeException before the caller changes the cache */
    public static function exclusive(string $buildDir, float $timeout = self::EXCLUSIVE_TIMEOUT_SECONDS): self
    {
        $handle = self::open($buildDir);
        if ($handle !== null && self::lock($handle, LOCK_EX, $timeout)) {
            return new self($handle);
        }
        if ($handle !== null) {
            fclose($handle);
        }

        throw new \RuntimeException('Cannot lock the Symfony cache for refreshing; web requests may still be using it. Retry when they finish.');
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }

    /** @return resource|null */
    private static function open(string $buildDir)
    {
        $path = self::path($buildDir);
        $directory = dirname($path);
        if (!is_dir($directory)) {
            // The kernel creates the same tree with this mode when it builds.
            @mkdir($directory, 0o777, true);
        }
        // "e" sets close-on-exec. The web installer starts its worker through
        // a shell, and an inherited descriptor would keep this request's
        // shared lock for the worker's lifetime and block its own refresh.
        $handle = @fopen($path, 'ce');
        if ($handle === false) {
            // flock() does not need write access to an existing lock file.
            $handle = @fopen($path, 're');
        }

        return $handle === false ? null : $handle;
    }

    /**
     * @param resource $handle
     * @param LOCK_SH|LOCK_EX $operation
     */
    private static function lock($handle, int $operation, float $timeout): bool
    {
        $deadline = microtime(true) + $timeout;
        while (!flock($handle, $operation | LOCK_NB, $wouldBlock)) {
            // Windows does not report would-block; retry within the same bound.
            if (($wouldBlock !== 1 && PHP_OS_FAMILY !== 'Windows') || microtime(true) >= $deadline) {
                return false;
            }
            usleep(self::PAUSE_MICROSECONDS);
        }

        return true;
    }
}
