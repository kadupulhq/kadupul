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

// The page has no realm (permission_denied.php is -1 in
// $user_auth_realm_filenames), so include/auth.php admits any eligible account
// and nothing more is checked.
define('CACTI_LINK_SYNC_PERMISSIONS', true);
$consoleHandoff = [
    'page' => 'permission_denied.php',
    'path' => 'page_path',
    'suffix' => '/permission_denied.php',
    'keys' => [],
    'valid' => static fn(array $command): bool => true,
    'realm' => null,
    'run' => static fn(array $command): array => (new \Kadupul\Platform\Infrastructure\Legacy\PermissionDeniedRuntime())->capture(),
    'json' => 0,
];
require __DIR__ . '/legacy-console-handoff.php';
