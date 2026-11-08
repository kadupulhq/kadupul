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
    private const WARNING_INTERVAL_SECONDS = 3600;
    private const REFUSED = 'refused';
    private const DEFERRED = 'deferred';

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
     * A refused file is reported to the server log, at most once an hour.
     *
     * @throws CacheRefreshInProgress when a refresh holds the gate past the timeout
     */
    public static function shared(string $buildDir, float $timeout = self::SHARED_TIMEOUT_SECONDS): ?self
    {
        $gate = self::open(self::gatePath($buildDir));
        $lock = is_resource($gate) ? self::open(self::path($buildDir)) : null;
        if (!is_resource($gate) || !is_resource($lock)) {
            self::close($gate, $lock);
            if ($gate === self::REFUSED || $lock === self::REFUSED) {
                self::warnRefused($buildDir);
            }

            return null;
        }
        $deadline = microtime(true) + $timeout;
        if (!self::lock($gate, LOCK_SH, $deadline)) {
            self::close($gate, $lock);

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

    /**
     * A root-run refresh in a directory the application user owns does not
     * create missing lock files. That one run proceeds unlocked and says so;
     * the next web request creates the files as the application user.
     *
     * @throws \RuntimeException before the caller changes the cache
     */
    public static function exclusive(string $buildDir, float $timeout = self::EXCLUSIVE_TIMEOUT_SECONDS): self
    {
        $deadline = microtime(true) + $timeout;
        $gate = self::open(self::gatePath($buildDir));
        $lock = is_resource($gate) ? self::open(self::path($buildDir)) : null;
        if (is_resource($gate) && is_resource($lock)) {
            if (self::lock($gate, LOCK_EX, $deadline)) {
                // New readers now wait at the gate; only running requests remain.
                if (self::lock($lock, LOCK_EX, $deadline)) {
                    return new self([$lock, $gate]);
                }
                flock($gate, LOCK_UN);
            }
            self::close($gate, $lock);

            throw new \RuntimeException('Cannot lock the Symfony cache for refreshing; web requests are still using it. Retry when they finish.');
        }
        self::close($gate, $lock);
        if ($gate !== self::REFUSED && $lock !== self::REFUSED && ($gate === self::DEFERRED || $lock === self::DEFERRED)) {
            error_log(sprintf('Kadupul: refreshing the Symfony cache as root without the web request lock, because %s or %s does not exist yet. Run cache refreshes as the application user.', self::gatePath($buildDir), self::path($buildDir)));

            return new self([]);
        }

        throw new \RuntimeException('Cannot create private Symfony cache lock files. The directory containing the cache must be owned by the application user and not writable by other users, and existing lock files must be private regular files.');
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

    /** @return resource|self::REFUSED|self::DEFERRED */
    private static function open(string $path)
    {
        $directory = dirname($path);
        $euid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $posix = PHP_OS_FAMILY !== 'Windows';
        // Create both restrictive from the start rather than chmod() later,
        // so no other user can open a fresh lock file. umask() is
        // process-wide; it is restored before any other work.
        $mask = umask(umask() | self::UMASK);
        try {
            if (!is_dir($directory)) {
                if ($euid === 0) {
                    return self::DEFERRED;
                }
                @mkdir($directory, self::DIRECTORY_MODE, true);
            }
            clearstatcache(true, $directory);
            $directoryStat = @stat($directory);
            if ($directoryStat === false || ($posix && !CacheLockPolicy::directoryUsable($directoryStat, $euid))) {
                return self::REFUSED;
            }
            // "e" sets close-on-exec. The web installer starts its worker
            // through a shell, and an inherited descriptor would keep this
            // request's shared lock for the worker's lifetime and block its
            // own refresh. flock() does not need write access, so an
            // existing file opens read-only.
            $handle = @fopen($path, 're');
            if ($handle === false && !file_exists($path) && !is_link($path)) {
                if ($posix && !CacheLockPolicy::mayCreate($euid, $directoryStat['uid'])) {
                    return self::DEFERRED;
                }
                $handle = @fopen($path, 'xe');
                if ($handle === false) {
                    // Another process may have created it in between.
                    $handle = @fopen($path, 're');
                }
            }
        } finally {
            umask($mask);
        }
        if ($handle === false) {
            return self::REFUSED;
        }
        $stat = fstat($handle);
        if ($posix && ($stat === false || !CacheLockPolicy::fileUsable($stat, is_link($path), $euid, $directoryStat['uid']))) {
            fclose($handle);

            return self::REFUSED;
        }

        return $handle;
    }

    /** @param resource|string|null ...$handles */
    private static function close(...$handles): void
    {
        foreach ($handles as $handle) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * Rate-limited through an exclusively created marker in the temporary
     * directory, so concurrent requests log one warning per interval. Only a
     * marker this user owns counts: the name is predictable, and another local
     * user who kept a fresh one there must not be able to silence the warning.
     */
    private static function warnRefused(string $buildDir): void
    {
        $marker = sys_get_temp_dir() . '/kadupul-cache-lock-warning-' . hash('sha256', $buildDir);
        $stat = self::ownMarker($marker);
        if ($stat !== null && time() - $stat['mtime'] < self::WARNING_INTERVAL_SECONDS) {
            return;
        }
        if (@lstat($marker) !== false) {
            @unlink($marker);
        }
        $handle = @fopen($marker, 'xe');
        if ($handle !== false) {
            fclose($handle);
        } elseif (self::ownMarker($marker) !== null) {
            // A concurrent request of this user created it and logs instead.
            return;
        }
        error_log(sprintf('Kadupul: web requests are running without the Symfony cache lock, so a cache refresh can make them fail. Refused %s or %s: each must be a regular file with mode 0640 or stricter, owned by the application user, in a directory other users cannot write. Delete them to let the application user recreate them.', self::gatePath($buildDir), self::path($buildDir)));
    }

    /**
     * Returns the marker's lstat() result when it is a regular file owned by
     * the effective user, or null when it is missing or belongs to anyone else.
     *
     * @return array<int|string, int>|null
     */
    private static function ownMarker(string $marker): ?array
    {
        clearstatcache(true, $marker);
        $stat = @lstat($marker);
        if ($stat === false || is_link($marker) || !is_file($marker)) {
            return null;
        }
        $euid = function_exists('posix_geteuid') ? posix_geteuid() : null;

        return $euid === null || $stat['uid'] === $euid ? $stat : null;
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
