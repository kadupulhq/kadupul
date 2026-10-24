<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Graphing\Infrastructure\Rrd;

final readonly class RrdtoolResult
{
    private const DIAGNOSTIC_LENGTH = 200;

    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}

    /**
     * The first line of rrdtool's error, safe to publish: rrdtool names the
     * file it was given, so anything that looks like a path is replaced.
     */
    public function diagnostic(): string
    {
        $line = trim(strtok($this->stderr, "\r\n") ?: '');
        $line = (string) preg_replace('/\AERROR:\s*/', '', $line);
        $line = (string) preg_replace('~\S*[/\\\\]\S*~', '[file]', $line);
        $line = (string) preg_replace('/[^\x20-\x7e]/', '?', $line);

        return $line === '' ? 'no error text' : substr($line, 0, self::DIAGNOSTIC_LENGTH);
    }
}
