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

// Same handoff as bin/legacy-console-home.php. The page has no realm
// (permission_denied.php is -1 in $user_auth_realm_filenames), so
// include/auth.php admits any eligible account and nothing more is checked.
define('KADUPUL_BROWSER_COMPATIBILITY', true);
define('CACTI_LINK_SYNC_PERMISSIONS', true);
define('KADUPUL_REDACT_DATABASE_LOGS', true);
define('KADUPUL_THROW_DATABASE_ERRORS', true);
$phase = 'handoff';
ob_start();
try {
    $raw = stream_get_contents(STDIN, 65538);
    $command = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (strlen($raw) > 65536 || !is_array($command)
        || array_diff(array_keys($command), ['actor', 'credential', 'session_id', 'session_path', 'page_path', 'server', 'cookies', 'query']) !== []
        || !is_int($command['actor'] ?? null) || $command['actor'] < 1
        || !is_string($command['credential'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $command['credential']) !== 1
        || !is_string($command['session_id'] ?? null) || preg_match('/\A[a-zA-Z0-9,-]{22,256}\z/D', $command['session_id']) !== 1
        || !is_string($command['session_path'] ?? null) || str_contains($command['session_path'], "\0")
        || !is_string($command['page_path'] ?? null) || !str_starts_with($command['page_path'], '/')
        || str_starts_with($command['page_path'], '//') || preg_match('/[\x00-\x20\\\\?#]/', $command['page_path'])
        || !str_ends_with($command['page_path'], '/permission_denied.php')
        || !is_array($command['server'] ?? null) || !is_array($command['cookies'] ?? null) || !is_array($command['query'] ?? null)) {
        throw new InvalidArgumentException('Invalid permission denied handoff.');
    }
    $root = dirname(__DIR__);
    $queryString = $command['server']['QUERY_STRING'] ?? '';
    if (!is_string($queryString)) {
        throw new InvalidArgumentException('Invalid permission denied query string.');
    }
    $_SERVER = array_replace($_SERVER, $command['server'], [
        'SCRIPT_FILENAME' => $root . '/permission_denied.php', 'SCRIPT_NAME' => $command['page_path'],
        'PHP_SELF' => $command['page_path'],
        'REQUEST_URI' => $command['page_path'] . ($queryString === '' ? '' : '?' . $queryString), 'REQUEST_METHOD' => 'GET',
    ]);
    $_COOKIE = $command['cookies'];
    $_GET = $_REQUEST = $command['query'];
    $_POST = [];
    chdir($root . '/include');
    session_save_path($command['session_path']);
    session_id($command['session_id']);
    $phase = 'bootstrap';
    require __DIR__ . '/../include/auth.php';
    chdir($root);
    $phase = 'identity';
    if ((int) ($_SESSION['sess_user_id'] ?? 0) !== $command['actor']
        || !is_string($_SESSION['sess_user_credential'] ?? null)
        || !hash_equals($command['credential'], $_SESSION['sess_user_credential'])) {
        throw new RuntimeException('Permission denied actor was revoked.');
    }
    $phase = 'extensions';
    $result = (new \Kadupul\Platform\Infrastructure\Legacy\PermissionDeniedRuntime())->capture();
    $phase = 'session';
    if (!session_write_close()) {
        throw new RuntimeException('Permission denied session changes could not be persisted.');
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode(['status' => 'ok', 'actor' => $command['actor']] + $result, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    // Only fixed diagnostic fields cross stderr. Never emit exception messages,
    // arguments, captured HTML, session data or credentials.
    fwrite(STDERR, json_encode(['phase' => $phase, 'class' => get_class($error),
        'file' => basename($error->getFile()), 'line' => $error->getLine()], JSON_THROW_ON_ERROR));
    echo '{"status":"failed"}';
    exit(1);
}
