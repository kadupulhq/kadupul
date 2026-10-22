<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Ported from scripts/unix_users.pl.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/script_command.php';

/** The user name to match, or '' to count every session; unix_users.pl's allow-list. */
function unix_users_filter(string $argument): string
{
    return preg_match('/^([\w]+)$/', $argument, $match) === 1 ? $match[1] : '';
}

/** Counts `who` lines holding a colon and, when given, the user name, as the grep pipeline did. */
function unix_users_count(string|false $who, string $user): int
{
    $lines = array_filter(explode("\n", (string) $who), static fn(string $line): bool => str_contains($line, ':') && str_contains($line, $user));

    return count($lines);
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    print unix_users_count(script_command_output(['who']), unix_users_filter((string) ($_SERVER['argv'][1] ?? '')));
}
