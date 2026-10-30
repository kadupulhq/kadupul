<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// The worker presents about.php, which has no realm, because the menu is drawn
// on every console page; the caller filters each entry by its own realm. Only
// data is returned, never HTML.
$consoleHandoff = [
    'page' => 'about.php',
    'path' => 'page_path',
    'suffix' => '/about.php',
    'keys' => ['sections'],
    'valid' => static fn(array $command): bool => is_array($command['sections'] ?? null) && array_is_list($command['sections'])
        && count($command['sections']) === count(array_filter($command['sections'], 'is_string')),
    'realm' => null,
    'run' => static fn(array $command): array => (new \Kadupul\Platform\Infrastructure\Legacy\ConsoleMenuRuntime())->capture(
        is_array($GLOBALS['menu'] ?? null) ? $GLOBALS['menu'] : [],
        is_array($GLOBALS['user_auth_realm_filenames'] ?? null) ? $GLOBALS['user_auth_realm_filenames'] : [],
        $command['sections'],
        static fn(string $text): string => (string) __($text),
    ),
    'json' => JSON_INVALID_UTF8_SUBSTITUTE,
];
require __DIR__ . '/legacy-console-handoff.php';
