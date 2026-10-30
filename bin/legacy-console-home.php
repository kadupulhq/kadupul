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

// Refresh native permission caches in place, as the migrated link viewer does.
define('CACTI_LINK_SYNC_PERMISSIONS', true);
$consoleHandoff = [
    'page' => 'index.php',
    'path' => 'index_path',
    'suffix' => '',
    'keys' => [],
    'valid' => static fn(array $command): bool => true,
    'realm' => 8,
    'run' => static fn(array $command): array => (new \Kadupul\Platform\Infrastructure\Legacy\ConsoleExtensionRuntime())->capture(),
    'json' => 0,
];
require __DIR__ . '/legacy-console-handoff.php';
