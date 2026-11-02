<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\Fixtures;

/** A PHP stream that admits metadata while refusing open or ending a read without EOF. */
final class InstallerReadFailureStream
{
    /** PHP assigns the stream context before opening the wrapper. */
    public mixed $context;
    /** Select the native open-failure or interrupted-read stage. */
    public static bool $open = false;

    /** @return array<string, int> */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0100444, 'size' => 1, 'nlink' => 1];
    }
    /** Preserve PHP's by-reference resolved-path contract. */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return self::$open;
    }
    /** No bytes were obtained, but the source did not confirm EOF. */
    public function stream_read(int $count): string|false
    {
        return false;
    }
    /** Model a failed short read rather than a successful empty file. */
    public function stream_eof(): bool
    {
        return false;
    }
    /** CSV position bookkeeping remains valid during a failed read. */
    public function stream_tell(): int
    {
        return 0;
    }
    /** PHP's stream options are accepted independently of payload delivery. */
    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return true;
    }
    /** Closing an admitted stream has no caller-owned resources to release. */
    public function stream_close(): void {}
}
