<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/Helpers/PhpSource.php';
$source = file_get_contents($argv[1] ?? dirname(__DIR__, 2) . '/data_queries.php');
if ($source === false) { throw new RuntimeException('Missing confirmation source.'); }
$calls = [];
function get_filter_request_var(string $key): void { $GLOBALS['calls'][] = ['validate', $key]; }
function get_request_var(string $key): int { return $key === 'id' ? 7 : 4; }
function form_start(string $action): void { echo '<form action="' . htmlspecialchars($action, ENT_QUOTES) . '">'; }
function html_start_box(mixed ...$arguments): void { echo '<table>'; }
function db_fetch_row_prepared(string $sql, array $parameters): array {
    $GLOBALS['calls'][] = ['query', preg_replace('/\s+/', ' ', trim($sql)), $parameters];
    return ['name' => 'Mapping <img src=x onerror=alert(1)>'];
}
function __(string $message, mixed ...$arguments): string { return $arguments === [] ? $message : sprintf($message, ...$arguments); }
function __esc(string $message, mixed ...$arguments): string { return htmlspecialchars(__($message, ...$arguments), ENT_QUOTES); }
function html_end_box(): void { echo '</table>'; }
function form_end(): void { echo '</form>'; }
$keys = ['vdef_functions', 'vdef_item_types', 'custom_vdef_data_source_types'];
foreach ($keys as $key) { unset($GLOBALS[$key]); }
eval(test_php_function_source($source, 'data_query_item_remove_confirm'));
ob_start();
data_query_item_remove_confirm();
$html = ob_get_clean();
$created = array_values(array_filter($keys, static fn(string $key): bool => array_key_exists($key, $GLOBALS)));
echo json_encode(['html' => $html, 'calls' => $calls, 'created' => $created], JSON_THROW_ON_ERROR);
