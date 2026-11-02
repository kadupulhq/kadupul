<?php

declare(strict_types=1);
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
// Preserve the native login, account policy, realm 26 and collector bootstrap.
// Symfony owns the workflow only after that boundary admits this request.
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, HEAD, POST');
    http_response_code(405);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !is_string($_POST['__csrf_magic'] ?? null)) {
    http_response_code(403);
    exit;
}
define('IN_CACTI_INSTALL', true);
define('KADUPUL_INSTALLER_WEB', true);
define('KADUPUL_REDACT_DATABASE_LOGS', true);
define('KADUPUL_THROW_DATABASE_ERRORS', true);
$installerBufferLevel = ob_get_level();
chdir(dirname(__DIR__));
$auth_json = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? null) === 'XMLHttpRequest' || ($_GET['view'] ?? null) === 'status';
if ($auth_json) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
}
require __DIR__ . '/../include/auth.php';
require __DIR__ . '/installer-bootstrap.php';
// The Twig page supplies its own token fields and external CSP-safe script.
while (ob_get_level() > $installerBufferLevel) {
    ob_end_clean();
}
$kernel = require __DIR__ . '/../config/bootstrap.php';
$request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
$root = rtrim($config['url_path'] ?? '/', '/');
if (!str_starts_with($root, '/') && $root !== '' || str_starts_with($root, '//') || preg_match('/[\\x00-\\x20\\\\?#]/', $root)) {
    http_response_code(500);
    exit;
}
$request = $request->duplicate(server: array_replace($request->server->all(), [
    'SCRIPT_FILENAME' => dirname(__DIR__) . '/app.php',
    'SCRIPT_NAME' => $root . '/app.php',
    'PHP_SELF' => $root . '/app.php/installer',
    'REQUEST_URI' => $root . '/app.php/installer',
]));
$locale = $_SESSION['sess_user_language'] ?? null;
if (!is_string($locale) || $locale === '') {
    $locale = read_config_option('install_language', true) ?: read_config_option('i18n_default_language', true);
}
if (is_string($locale) && preg_match('/\\A[a-z]{2}(?:[-_][a-zA-Z]{2})?\\z/D', $locale)) {
    $request->attributes->set('_locale', str_replace('_', '-', $locale));
}
$response = $kernel->handle($request);
// global_session.php renders legacy inline JavaScript; it is not the session
// persistence boundary. Native PHP session shutdown persists this request.
// Native authentication supplied the login page's policy before Symfony ran.
// This standalone page has its own stricter policy and no legacy inline code.
header_remove('Content-Security-Policy');
header_remove('Content-Security-Policy-Report-Only');
$response->send();
$kernel->terminate($request, $response);
