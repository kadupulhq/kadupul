<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Render production graph controls for the browser, supplying an authorized
// graph and no unrelated device/template actions. Never expose this via HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../Helpers/PhpSource.php';
require __DIR__ . '/../../lib/headers_secure.php';
$config = ['url_path' => '/'];
function read_config_option(string $name): string
{
    return $name === 'content_security_policy_script' ? 'nonce' : '';
}
function __esc(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
function aggregate_build_children_url(int $id): string
{
    return '';
}
function db_fetch_cell_prepared(string $sql, array $params): int
{
    return 0;
}
function is_realm_allowed(int $realm): bool
{
    return $realm === 1043;
}
function api_plugin_hook(string $name, array $args): void {}
$source = file_get_contents(__DIR__ . '/../../lib/html.php');
if ($source === false) {
    throw new RuntimeException('Unable to read the production renderer.');
}
foreach (['html_escape', 'graph_drilldown_icons', 'html_spikekill_js'] as $function) {
    eval(test_php_function_source($source, $function));
}
ob_start();
graph_drilldown_icons(100);
$icons = ob_get_clean();
ob_start();
html_spikekill_js();
$script = ob_get_clean();
$nonce = CactiSecureHeaders::getNonce();
echo json_encode(['icons' => $icons, 'script' => $script, 'nonce' => $nonce, 'csp' => CactiSecureHeaders::buildCspPolicy('nonce', $nonce, '')], JSON_THROW_ON_ERROR);
