<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Ported from scripts/unix_processes.pl.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/script_command.php';

/**
 * Counts the `ps ax` lines holding a colon, as `grep -c :` did. Every process
 * line has one in its TIME column. The Perl count also included its own sh
 * and grep processes; this one has no shell or grep to count.
 */
function unix_processes_count(string|false $ps): int
{
    return count(array_filter(explode("\n", (string) $ps), static fn(string $line): bool => str_contains($line, ':')));
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    print unix_processes_count(script_command_output(['ps', 'ax']));
}
