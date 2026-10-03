<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Serialize local read/merge/verified replacement; callers propagate after return. */
function data_input_whitelist_update(string $configuredPath, callable $readRecords, bool $push, float $deadline): array
{
    if (!is_finite($deadline)) {
        throw new RuntimeException('Invalid whitelist deadline.');
    }
    if ($configuredPath === '' || str_contains($configuredPath, "\0") || str_contains($configuredPath, '://')) {
        throw new RuntimeException('Invalid whitelist path.');
    }
    $configuredDirectory = $directory = realpath(dirname($configuredPath));
    if ($directory === false || !is_dir($directory) || !is_writable($directory)) {
        throw new RuntimeException('Whitelist directory is unavailable or not writable.');
    }
    $path = $directory . DIRECTORY_SEPARATOR . basename($configuredPath);
    if (is_link($path)) {
        $path = realpath($path);
        if ($path === false) {
            throw new RuntimeException('Whitelist symlink has no existing target.');
        }
        $directory = dirname($path);
    }
    $lockPath = $path . '.lock';
    $lock = null;
    $temporary = null;
    $stream = null;
    $remaining = static function () use ($deadline): void {
        if (hrtime(true) / 1e9 >= $deadline) {
            throw new RuntimeException('Whitelist update deadline expired.');
        }
    };
    try {
        $remaining();
        $createdLock = false;
        clearstatcache(true, $lockPath);
        if (!file_exists($lockPath) && !is_link($lockPath)) {
            $lock = @fopen($lockPath, 'x+b');
            $createdLock = is_resource($lock);
        }
        if (!is_resource($lock)) {
            $entry = @lstat($lockPath);
            if (!$entry || ($entry['mode'] & 0170000) !== 0100000 || $entry['nlink'] !== 1) {
                throw new RuntimeException('Whitelist lock is not a regular private inode.');
            }
            $lock = @fopen($lockPath, 'r+b');
        }
        $opened = is_resource($lock) ? fstat($lock) : false;
        clearstatcache(true, $lockPath);
        $entry = @lstat($lockPath);
        if (!$opened || !$entry || ($entry['mode'] & 0170000) !== 0100000 || $entry['nlink'] !== 1
            || $opened['dev'] !== $entry['dev'] || $opened['ino'] !== $entry['ino']) {
            throw new RuntimeException('Whitelist lock identity changed.');
        }
        if ($createdLock) {
            $targetMetadata = @stat($path);
            if ($targetMetadata && PHP_OS_FAMILY !== 'Windows'
                && (($opened['uid'] !== $targetMetadata['uid'] && !chown($lockPath, $targetMetadata['uid']))
                    || ($opened['gid'] !== $targetMetadata['gid'] && !chgrp($lockPath, $targetMetadata['gid'])))) {
                throw new RuntimeException('Whitelist lock ownership could not be preserved.');
            }
            if (!chmod($lockPath, ($targetMetadata ? $targetMetadata['mode'] : 0666 & ~umask()) & 0666)) {
                throw new RuntimeException('Whitelist lock permissions could not be set.');
            }
        }
        while (!flock($lock, LOCK_EX | LOCK_NB)) {
            $remaining();
            usleep(10000);
        }
        $remaining();
        // Query only after ownership: a waiting full update must not publish a
        // database snapshot older than another successful whitelist update.
        [$records, $idHash] = $readRecords();
        $remaining();
        clearstatcache(true, $path);
        $prior = @lstat($path);
        if ($prior && (($prior['mode'] & 0170000) !== 0100000 || $prior['nlink'] !== 1 || ($prior['mode'] & 0222) === 0 || !is_writable($path))) {
            throw new RuntimeException('Whitelist target must be a writable regular file without hardlink aliases.');
        }
        $whitelist = [];
        if ($prior) {
            $bytes = @file_get_contents($path);
            if ($bytes === false) {
                throw new RuntimeException('Existing whitelist could not be read.');
            }
            $whitelist = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($whitelist)) {
                throw new RuntimeException('Existing whitelist must contain a JSON map.');
            }
        }
        $input = $pushes = [];
        foreach ($records as $record) {
            $remaining();
            $hash = $record['hash'];
            $command = $record['input_string'];
            if ($idHash === false || $idHash === $hash) {
                if ($push && isset($whitelist[$hash]) && $command !== $whitelist[$hash]) {
                    $pushes[$record['id']] = $record['name'];
                }
                $input[$hash] = $command;
            } else {
                $input[$hash] = $whitelist[$hash] ?? $command;
            }
        }
        $bytes = json_encode($input, JSON_THROW_ON_ERROR);
        $remaining();
        $temporary = $directory . DIRECTORY_SEPARATOR . '.whitelist-' . bin2hex(random_bytes(16));
        $stream = @fopen($temporary, 'x+b');
        if (!is_resource($stream)) {
            throw new RuntimeException('Whitelist replacement could not be created.');
        }
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $remaining();
            $written = @fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Whitelist replacement could not be completely written.');
            }
            $offset += $written;
        }
        if (!fflush($stream) || (function_exists('fsync') && !fsync($stream)) || !rewind($stream) || stream_get_contents($stream) !== $bytes) {
            throw new RuntimeException('Whitelist replacement could not be verified.');
        }
        $metadata = fstat($stream);
        if ($metadata === false) {
            throw new RuntimeException('Whitelist replacement metadata unavailable.');
        }
        if ($prior && PHP_OS_FAMILY !== 'Windows'
            && (($metadata['uid'] !== $prior['uid'] && !chown($temporary, $prior['uid']))
                || ($metadata['gid'] !== $prior['gid'] && !chgrp($temporary, $prior['gid'])))) {
            throw new RuntimeException('Whitelist replacement ownership could not be preserved.');
        }
        if (!chmod($temporary, $prior ? $prior['mode'] & 0777 : 0666 & ~umask())) {
            throw new RuntimeException('Whitelist replacement permissions could not be set.');
        }
        $remaining();
        clearstatcache(true, $path);
        $current = @lstat($path);
        clearstatcache(true, $lockPath);
        $currentLock = @lstat($lockPath);
        // Long-lived leaf processes may cache configured symlink resolutions.
        clearstatcache(true);
        if (realpath(dirname($configuredPath)) !== $configuredDirectory || ($prior && realpath($configuredPath) !== $path)
            || ($prior && (!$current || $current['dev'] !== $prior['dev'] || $current['ino'] !== $prior['ino']))
            || (!$prior && $current) || !$currentLock || $currentLock['dev'] !== $opened['dev'] || $currentLock['ino'] !== $opened['ino']) {
            throw new RuntimeException('Whitelist destination or lock changed during update.');
        }
        // Windows cannot rename an open replacement. All bytes and metadata are
        // verified before closing; the local sidecar remains exclusively owned.
        fclose($stream);
        $stream = null;
        if (!@rename($temporary, $path)) {
            throw new RuntimeException('Whitelist replacement could not be published.');
        }
        $temporary = null;
        clearstatcache(true);
        if (realpath(dirname($configuredPath)) !== $configuredDirectory || realpath($configuredPath) !== $path || @file_get_contents($path) !== $bytes) {
            throw new RuntimeException('Whitelist replacement was published but readback could not be confirmed.');
        }
        return $pushes;
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
        if ($temporary !== null) {
            @unlink($temporary);
        }
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        // Keep the stable sidecar inode: unlinking it would split future waiters.
    }
}
