<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Faults only the filesystem boundary; boost_graph_set_file() runs unchanged.
final class BoostCacheWriteStream
{
    public mixed $context;
    public static string $directory;
    public static string $fault;
    public static int $renameAttempts = 0;
    private mixed $handle;
    private bool $wrote = false;

    public static function path(string $url): string
    {
        return self::$directory . '/' . basename($url);
    }

    public function url_stat(string $url, int $flags): array|false
    {
        return @stat($url === 'boostwrite://cache' ? self::$directory : self::path($url));
    }

    public function stream_open(string $url, string $mode, int $options, ?string &$openedPath): bool
    {
        if (self::$fault === 'open') return false;
        $this->handle = fopen(self::path($url), $mode);
        return $this->handle !== false;
    }

    public function stream_write(string $bytes): int|false
    {
        if (self::$fault === 'short') {
            if ($this->wrote) return 0;
            $this->wrote = true;
            return fwrite($this->handle, substr($bytes, 0, 4));
        }
        return fwrite($this->handle, $bytes);
    }

    public function stream_stat(): array|false
    {
        return fstat($this->handle);
    }

    public function stream_flush(): bool
    {
        return fflush($this->handle);
    }

    public function stream_close(): void
    {
        fclose($this->handle);
    }

    public function rename(string $from, string $to): bool
    {
        self::$renameAttempts++;
        return self::$fault !== 'rename' && rename(self::path($from), self::path($to));
    }

    public function unlink(string $url): bool
    {
        return unlink(self::path($url));
    }
}
