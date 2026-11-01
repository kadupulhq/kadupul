<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Profiling;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * One JSON line per profiled request. Legacy pages construct this directly
 * from a shutdown function, so it must not depend on the container.
 */
final readonly class RequestProfileLog
{
    public function __construct(
        #[Autowire(env: 'KADUPUL_PROFILE_LOG')]
        private string $path,
    ) {}

    /**
     * Appends one record; a malformed id, an unwritable directory or an unencodable record is dropped silently.
     *
     * @param float $ms elapsed milliseconds
     * @param ?int $queries null when the query counter is unavailable
     */
    public function append(string $id, string $path, float $ms, ?int $queries, int $peakBytes): void
    {
        // The id comes from a request header; anything outside this grammar
        // could otherwise forge or split a log record.
        if (preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $id) !== 1) {
            return;
        }

        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            return;
        }

        $line = json_encode(
            ['id' => $id, 'path' => $path, 'ms' => round($ms, 3), 'queries' => $queries, 'peak_bytes' => $peakBytes],
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if ($line === false) {
            return;
        }

        file_put_contents($this->path, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
