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
 * opening required". Web kernels hold a shared lock on "<build dir>.lock"
 * from boot to shutdown; cache:clear holds it exclusively while it runs.
 *
 * flock() gives a waiting writer no priority, so overlapping readers could
 * keep a refresh out forever. A writer therefore first takes the gate
 * "<build dir>.gate" exclusively. Readers pass the gate with a shared lock
 * held only while they take the main lock, so a writer that holds the gate
 * waits only for requests that were already running.
 */
final class CacheDirectoryLock
{
    /** Short enough to answer 503 well before typical proxy timeouts. */
    public const SHARED_TIMEOUT_SECONDS = 15.0;
    public const EXCLUSIVE_TIMEOUT_SECONDS = 30.0;
    private const PAUSE_MICROSECONDS = 20000;
    private const DIRECTORY_MODE = 0o750;
    /** Applied to fopen's default 0666, lock files are created 0640 or stricter. */
    private const UMASK = 0o027;

    /** @var list<resource> */
    private array $handles;

    /** @param list<resource> $handles */
    private function __construct(array $handles)
    {
        $this->handles = $handles;
    }

    /** Siblings: cache:clear renames and removes the directory itself. */
    public static function path(string $buildDir): string
    {
        return rtrim($buildDir, '/\\') . '.lock';
    }

    public static function gatePath(string $buildDir): string
    {
        return rtrim($buildDir, '/\\') . '.gate';
    }

    /**
     * Returns null when no lock can be held safely: a lock file that cannot
     * be created, or one that is not a private regular file. A refresh
     * refuses the same files, so it cannot swap the cache under this reader.
     *
     * @throws CacheRefreshInProgress when a refresh holds the gate past the timeout
     */
    public static function shared(string $buildDir, float $timeout = self::SHARED_TIMEOUT_SECONDS): ?self
    {
        $gate = self::open(self::gatePath($buildDir));
        if ($gate === null) {
            return null;
        }
        $lock = self::open(self::path($buildDir));
        if ($lock === null) {
            fclose($gate);

            return null;
        }
        $deadline = microtime(true) + $timeout;
        if (!self::lock($gate, LOCK_SH, $deadline)) {
            fclose($gate);
            fclose($lock);

            throw new CacheRefreshInProgress();
        }
        // A writer takes the main lock only while it holds the gate, so this
        // waits at most for a writer that is releasing both.
        $locked = self::lock($lock, LOCK_SH, $deadline);
        flock($gate, LOCK_UN);
        fclose($gate);
        if (!$locked) {
            fclose($lock);

            throw new CacheRefreshInProgress();
        }

        return new self([$lock]);
    }

    /** @throws \RuntimeException before the caller changes the cache */
    public static function exclusive(string $buildDir, float $timeout = self::EXCLUSIVE_TIMEOUT_SECONDS): self
    {
        $deadline = microtime(true) + $timeout;
        $gate = self::open(self::gatePath($buildDir));
        $lock = $gate === null ? null : self::open(self::path($buildDir));
        if ($gate !== null && $lock !== null && self::lock($gate, LOCK_EX, $deadline)) {
            // New readers now wait at the gate; only running requests remain.
            if (self::lock($lock, LOCK_EX, $deadline)) {
                return new self([$lock, $gate]);
            }
            flock($gate, LOCK_UN);
        }
        foreach ([$lock, $gate] as $handle) {
            if ($handle !== null) {
                fclose($handle);
            }
        }

        if ($gate === null || $lock === null) {
            throw new \RuntimeException('Cannot create private Symfony cache lock files. The directory containing the cache must be owned by the application user and not writable by other users, and existing lock files must be private regular files.');
        }

        throw new \RuntimeException('Cannot lock the Symfony cache for refreshing; web requests are still using it. Retry when they finish.');
    }

    /** Releases the main lock before the gate, so no reader passes the gate early. */
    public function release(): void
    {
        foreach ($this->handles as $handle) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        $this->handles = [];
    }

    public function __destruct()
    {
        $this->release();
    }

    /** @return resource|null */
    private static function open(string $path)
    {
        $directory = dirname($path);
        // Create both restrictive from the start rather than chmod() later,
        // so no other user can open a fresh lock file. umask() is
        // process-wide; it is restored before any other work.
        $mask = umask(umask() | self::UMASK);
        try {
            if (!is_dir($directory)) {
                @mkdir($directory, self::DIRECTORY_MODE, true);
            }
            if (!self::privateDirectory($directory)) {
                return null;
            }
            // "e" sets close-on-exec. The web installer starts its worker
            // through a shell, and an inherited descriptor would keep this
            // request's shared lock for the worker's lifetime and block its
            // own refresh. flock() does not need write access, so an
            // existing file opens read-only.
            $handle = @fopen($path, 'xe');
            if ($handle === false) {
                $handle = @fopen($path, 're');
            }
        } finally {
            umask($mask);
        }
        if ($handle === false) {
            return null;
        }
        if (!self::privateFile($path, $handle)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /**
     * No other user may create or replace lock files beside the cache: the
     * directory is owned by this process or root (any owner when root runs)
     * and is not writable by other users. Group members who can write it can
     * already change the compiled PHP in the cache, so group write is allowed.
     */
    private static function privateDirectory(string $directory): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return true;
        }
        clearstatcache(true, $directory);
        $stat = @stat($directory);
        if ($stat === false || ($stat['mode'] & 0o002) !== 0) {
            return false;
        }
        if (!function_exists('posix_geteuid')) {
            return true;
        }
        $euid = posix_geteuid();

        return $euid === 0 || $stat['uid'] === $euid || $stat['uid'] === 0;
    }

    /**
     * Accept only a regular file, not a symlink, with no access for other
     * users, owned by this process, root or the directory owner.
     *
     * @param resource $handle
     */
    private static function privateFile(string $path, $handle): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return true;
        }
        $stat = fstat($handle);
        if (is_link($path) || $stat === false || ($stat['mode'] & 0o170000) !== 0o100000 || ($stat['mode'] & 0o007) !== 0) {
            return false;
        }

        return !function_exists('posix_geteuid') || in_array($stat['uid'], [posix_geteuid(), 0, @fileowner(dirname($path))], true);
    }

    /**
     * @param resource $handle
     * @param int<0, 7> $operation
     */
    private static function lock($handle, int $operation, float $deadline): bool
    {
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
