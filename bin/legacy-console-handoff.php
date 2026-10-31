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

// The browser compatibility workers share this handoff. Each sets
// $consoleHandoff and requires this file from global scope, because
// include/auth.php and the procedural globals it builds must load there.
// The worker still resumes and authorizes the actual session through
// include/auth.php; actor IDs alone never grant access. No procedural globals
// enter the Symfony process.
//
// $consoleHandoff holds: page, the native script presented to include/auth.php;
// path, the command key carrying its URL path; suffix, the required path
// ending; keys and valid, the worker's extra command keys and their check;
// realm, a realm the actor must also hold, or null; run, which returns the
// result once the actor is confirmed; json, extra json_encode flags. An optional
// final input_limit setting allows a bounded 1 MiB mutation payload; older
// read workers retain their 64 KiB budget. An optional final realm_denial boolean
// requests a typed result from the native core realm-denial renderer. Reskin or
// plugin-owned exits retain their native behavior. Neither option is command input.
define('KADUPUL_BROWSER_COMPATIBILITY', true);
define('KADUPUL_REDACT_DATABASE_LOGS', true);
define('KADUPUL_THROW_DATABASE_ERRORS', true);
$phase = 'handoff';
ob_start();
try {
    // A worker that omits a setting must fail closed, not skip its check.
    $consoleHandoffInputLimit = array_key_exists('input_limit', $consoleHandoff) ? $consoleHandoff['input_limit'] : 65536;
    $consoleHandoffRealmDenial = array_key_exists('realm_denial', $consoleHandoff) ? $consoleHandoff['realm_denial'] : false;
    $consoleHandoffKeys = ['page', 'path', 'suffix', 'keys', 'valid', 'realm', 'run', 'json'];
    if (array_key_exists('input_limit', $consoleHandoff)) {
        $consoleHandoffKeys[] = 'input_limit';
    }
    if (array_key_exists('realm_denial', $consoleHandoff)) {
        $consoleHandoffKeys[] = 'realm_denial';
    }
    if (array_keys($consoleHandoff) !== $consoleHandoffKeys
        || !is_bool($consoleHandoffRealmDenial)
        || !in_array($consoleHandoffInputLimit, [65536, 1048576], true)
        || !is_int($consoleHandoff['realm']) && $consoleHandoff['realm'] !== null) {
        throw new LogicException('Invalid console handoff configuration.');
    }
    $raw = stream_get_contents(STDIN, $consoleHandoffInputLimit + 2);
    $command = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    $pathKey = $consoleHandoff['path'];
    if (strlen($raw) > $consoleHandoffInputLimit || !is_array($command)
        || array_diff(array_keys($command), ['actor', 'credential', 'session_id', 'session_path', $pathKey, ...$consoleHandoff['keys'], 'server', 'cookies', 'query']) !== []
        || !is_int($command['actor'] ?? null) || $command['actor'] < 1
        || !is_string($command['credential'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $command['credential']) !== 1
        || !is_string($command['session_id'] ?? null) || preg_match('/\A[a-zA-Z0-9,-]{22,256}\z/D', $command['session_id']) !== 1
        || !is_string($command['session_path'] ?? null) || str_contains($command['session_path'], "\0")
        || !is_string($command[$pathKey] ?? null) || !str_starts_with($command[$pathKey], '/')
        || str_starts_with($command[$pathKey], '//') || preg_match('/[\x00-\x20\\\\?#]/', $command[$pathKey])
        || !str_ends_with($command[$pathKey], $consoleHandoff['suffix'])
        || !$consoleHandoff['valid']($command)
        || !is_array($command['server'] ?? null) || !is_array($command['cookies'] ?? null) || !is_array($command['query'] ?? null)) {
        throw new InvalidArgumentException('Invalid console handoff.');
    }
    if ($consoleHandoffRealmDenial) {
        define('KADUPUL_BROWSER_WORKER_REALM_DENIAL', true);
    }
    unset($consoleHandoffKeys, $consoleHandoffInputLimit, $consoleHandoffRealmDenial);
    $root = dirname(__DIR__);
    $queryString = $command['server']['QUERY_STRING'] ?? '';
    if (!is_string($queryString)) {
        throw new InvalidArgumentException('Invalid console query string.');
    }
    $_SERVER = array_replace($_SERVER, $command['server'], [
        'SCRIPT_FILENAME' => $root . '/' . $consoleHandoff['page'], 'SCRIPT_NAME' => $command[$pathKey],
        'PHP_SELF' => $command[$pathKey],
        'REQUEST_URI' => $command[$pathKey] . ($queryString === '' ? '' : '?' . $queryString), 'REQUEST_METHOD' => 'GET',
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
        || !hash_equals($command['credential'], $_SESSION['sess_user_credential'])
        || $consoleHandoff['realm'] !== null && !is_realm_allowed($consoleHandoff['realm'])) {
        throw new RuntimeException('Console actor was revoked.');
    }
    $phase = 'extensions';
    $result = $consoleHandoff['run']($command);
    // Persist compatibility extension session changes under the original native
    // handler and lock, instead of copying a stale parent snapshot back later.
    $phase = 'session';
    \Kadupul\Platform\Infrastructure\Legacy\BrowserWorkerSession::close();
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode(['status' => 'ok', 'actor' => $command['actor']] + $result, JSON_THROW_ON_ERROR | $consoleHandoff['json']);
} catch (Throwable $error) {
    if ($error instanceof \Kadupul\Platform\Infrastructure\Legacy\BrowserWorkerRealmDenied && $phase === 'bootstrap'
        && ($consoleHandoff['realm_denial'] ?? false) === true
        && defined('KADUPUL_BROWSER_WORKER_REALM_DENIAL') && KADUPUL_BROWSER_WORKER_REALM_DENIAL === true) {
        try {
            $phase = 'denial-identity';
            // The usual post-bootstrap check was not reached. Bind this denial
            // to the still-active real native session, never a client actor ID.
            if (session_status() !== PHP_SESSION_ACTIVE
                || !in_array($_SESSION['sess_user_id'] ?? null, [$command['actor'], (string) $command['actor']], true)
                || !is_string($_SESSION['sess_user_credential'] ?? null)
                || !hash_equals($command['credential'], $_SESSION['sess_user_credential'])) {
                throw new RuntimeException('Console denial identity could not be confirmed.');
            }
            $phase = 'denial-session';
            \Kadupul\Platform\Infrastructure\Legacy\BrowserWorkerSession::close();
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            echo json_encode(['status' => 'ok', 'actor' => $command['actor'], 'outcome' => 'denied'], JSON_THROW_ON_ERROR);
            return;
        } catch (Throwable $denialError) {
            $error = $denialError;
        }
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    // Only fixed diagnostic fields cross stderr. Never emit exception messages,
    // arguments, captured HTML, plugin output, session data or credentials.
    fwrite(STDERR, json_encode(['phase' => $phase, 'class' => get_class($error),
        'file' => basename($error->getFile()), 'line' => $error->getLine()], JSON_THROW_ON_ERROR));
    echo '{"status":"failed"}';
    exit(1);
}
