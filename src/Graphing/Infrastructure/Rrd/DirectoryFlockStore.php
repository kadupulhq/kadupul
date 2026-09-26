<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Rrd;

use Symfony\Component\Lock\BlockingStoreInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Exception\LockStorageException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\SharedLockStoreInterface;

/**
 * Symfony Lock store that retains the legacy RRD directory-inode lock protocol.
 *
 * FlockStore uses per-key files, which would not coordinate with older poller
 * processes that lock the RRA directory itself. This store delegates the
 * lock lifecycle to Symfony while preserving that cross-version inode lock.
 */
final class DirectoryFlockStore implements BlockingStoreInterface, SharedLockStoreInterface
{
    /**
     * @param string $path Original configured RRA directory path to revalidate.
     * @param string $canonicalPath Resolved directory path opened for locking.
     * @param int $device Expected filesystem device identifier.
     * @param int $inode Expected directory inode identifier.
     */
    public function __construct(
        private readonly string $path,
        private readonly string $canonicalPath,
        private readonly int $device,
        private readonly int $inode,
    ) {}

    /** Acquire an exclusive lock without waiting.
     *
     * @param Key $key Symfony lock key that owns the opened directory handle.
     *
     * @return void
     */
    public function save(Key $key): void
    {
        $this->lock($key, false, false);
    }

    /** Acquire a shared lock without waiting.
     *
     * @param Key $key Symfony lock key that owns the opened directory handle.
     *
     * @return void
     */
    public function saveRead(Key $key): void
    {
        $this->lock($key, true, false);
    }

    /** Acquire an exclusive lock and block until it is available.
     *
     * @param Key $key Symfony lock key that owns the opened directory handle.
     *
     * @return void
     */
    public function waitAndSave(Key $key): void
    {
        $this->lock($key, false, true);
    }

    /** Acquire a shared lock and block until it is available.
     *
     * @param Key $key Symfony lock key that owns the opened directory handle.
     *
     * @return void
     */
    public function waitAndSaveRead(Key $key): void
    {
        $this->lock($key, true, true);
    }

    /** Keep the interface compatible; directory flock locks do not expire.
     *
     * @param Key $key Acquired Symfony lock key.
     * @param float $ttl Ignored because the underlying flock has no lease timeout.
     *
     * @return void
     */
    public function putOffExpiration(Key $key, float $ttl): void
    {
        // flock is held by the open directory handle and does not expire.
    }

    /** Release the directory handle retained on the lock key.
     *
     * @param Key $key Symfony lock key to release.
     *
     * @return void
     */
    public function delete(Key $key): void
    {
        if (!$key->hasState(self::class)) {
            return;
        }

        $handle = $key->getState(self::class)[1];
        @flock($handle, LOCK_UN | LOCK_NB);
        fclose($handle);
        $key->removeState(self::class);
    }

    /** Check whether this key currently owns a directory handle.
     *
     * @param Key $key Symfony lock key to inspect.
     *
     * @return bool True when the key has an acquired lock state.
     */
    public function exists(Key $key): bool
    {
        return $key->hasState(self::class);
    }

    /** Open and flock the expected directory inode, rejecting path substitution.
     *
     * @param Key $key Symfony lock key receiving the open handle.
     * @param bool $read Whether to acquire a shared lock.
     * @param bool $blocking Whether flock should wait for another owner.
     *
     * @return void
     */
    private function lock(Key $key, bool $read, bool $blocking): void
    {
        $handle = null;
        if ($key->hasState(self::class)) {
            [$stateRead, $handle] = $key->getState(self::class);
            if ($stateRead === $read) {
                return;
            }
        }

        if (!$handle) {
            $handle = @fopen($this->canonicalPath, 'r');
        }
        if (!is_resource($handle)) {
            throw new LockStorageException('Unable to open the configured RRD directory for locking.');
        }

        $flags = ($read ? LOCK_SH : LOCK_EX) | ($blocking ? 0 : LOCK_NB);
        if (!@flock($handle, $flags)) {
            if (!$key->hasState(self::class)) {
                fclose($handle);
            }
            throw new LockConflictedException();
        }

        clearstatcache(true, $this->path);
        clearstatcache(true, $this->canonicalPath);
        $opened = fstat($handle);
        $current = @stat($this->path);
        $canonical = @stat($this->canonicalPath);
        if (!$opened || !$current || !$canonical
            || ($opened['mode'] & 0170000) !== 0040000
            || $opened['dev'] !== $this->device || $opened['ino'] !== $this->inode
            || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino']
            || $opened['dev'] !== $canonical['dev'] || $opened['ino'] !== $canonical['ino']) {
            @flock($handle, LOCK_UN);
            fclose($handle);
            throw new LockStorageException('The configured RRD directory changed while acquiring its lock.');
        }

        $key->setState(self::class, [$read, $handle]);
        $key->markUnserializable();
    }
}
