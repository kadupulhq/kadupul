<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

/**
 * Decides which cache lock files a process may create or trust, from stat()
 * results and the effective user ID (null when POSIX IDs are unavailable).
 * Kept free of I/O so the root-run decisions are testable unprivileged.
 */
final class CacheLockPolicy
{
    /**
     * No other user may create or replace lock files beside the cache: the
     * directory is owned by this process or root (any owner when root runs)
     * and is not writable by other users. Group members who can write it can
     * already change the compiled PHP in the cache, so group write is allowed.
     *
     * @param array{mode: int, uid: int} $directory
     */
    public static function directoryUsable(array $directory, ?int $euid): bool
    {
        if (($directory['mode'] & 0o002) !== 0) {
            return false;
        }

        return $euid === null || $euid === 0 || $directory['uid'] === $euid || $directory['uid'] === 0;
    }

    /**
     * Root must not create a lock file in a directory another user owns: PHP
     * cannot change a descriptor's owner, and a root-owned 0640 file would be
     * unreadable to the web user forever. That user creates it instead.
     */
    public static function mayCreate(?int $euid, int $directoryOwner): bool
    {
        return $euid !== 0 || $directoryOwner === 0;
    }

    /**
     * A regular file, not a symlink, with no access for other users, owned by
     * this process, root or the directory owner.
     *
     * @param array{mode: int, uid: int} $file
     */
    public static function fileUsable(array $file, bool $isLink, ?int $euid, int $directoryOwner): bool
    {
        if ($isLink || ($file['mode'] & 0o170000) !== 0o100000 || ($file['mode'] & 0o007) !== 0) {
            return false;
        }

        return $euid === null || in_array($file['uid'], [$euid, 0, $directoryOwner], true);
    }
}
