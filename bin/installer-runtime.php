<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Deliberately no Composer, Symfony, native bootstrap, session or database.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
try {
    $input = stream_get_contents(STDIN, 1025);
    if ($input === false || strlen($input) > 1024) {
        throw new RuntimeException();
    }
    $request = json_decode($input, true, 4, JSON_THROW_ON_ERROR);
    if (!is_array($request) || array_keys($request) !== ['nonce'] || !is_string($request['nonce'])
        || preg_match('/\A[a-f0-9]{64}\z/D', $request['nonce']) !== 1) {
        throw new RuntimeException();
    }
    require dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerRuntimeSnapshot.php';
    $snapshot = \Kadupul\Platform\Infrastructure\Legacy\InstallerRuntimeSnapshot::capture(dirname(__DIR__));
    $secret = getenv('APP_SECRET', true);
    echo json_encode(['protocol' => 1, 'snapshot' => $snapshot,
        'proof' => \Kadupul\Platform\Infrastructure\Legacy\InstallerRuntimeSnapshot::proof($snapshot, $request['nonce'], is_string($secret) ? $secret : '')], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    // No exception, stdin, snapshot or environment diagnostics reach stdout.
    echo '{"error":"runtime_attestation_unavailable"}';
    exit(1);
}
