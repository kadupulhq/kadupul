<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

namespace RealtimeScriptServerFixture;

[$program, $root, $directory] = $argv;
$scenario = json_decode(file_get_contents($directory . '/scenario.json'), true, 512, JSON_THROW_ON_ERROR);
require $root . '/tests/Helpers/PhpSource.php';
require $root . '/include/global_constants.php';
$config = ['base_path' => $directory . '/script root', 'library_path' => $root . '/lib', 'cacti_server_os' => PHP_OS_FAMILY === 'Windows' ? 'win32' : 'unix'];
$trace = ['queries' => [], 'opens' => [], 'gates' => [], 'shutdown_writes' => 0];
$owned = [];
foreach ($scenario['items'] as &$items) {
	foreach ($items as &$item) {
		if (is_string($item['action']) && str_starts_with($item['action'], 'POLLER_ACTION_')) $item['action'] = constant($item['action']);
	}
	unset($item);
}
unset($items);

function get_nfilter_request_var(string $name): mixed { return $GLOBALS['scenario']['request'][$name] ?? ''; }
function get_filter_request_var(string $name): mixed { return get_nfilter_request_var($name); }
function remote_agent_host_is_local(mixed $host): bool { $GLOBALS['trace']['gates'][] = $host; return $GLOBALS['scenario']['allowed'] ?? true; }
function remote_agent_host_belongs_to_authorized_poller(mixed $host): bool { return remote_agent_host_is_local($host); }
function die_html_input_error(): never { throw new \InvalidArgumentException('Invalid fixture request'); }
function read_config_option(string $name): string { return $name === 'path_php_binary' ? ($GLOBALS['scenario']['php_path'] ?? PHP_BINARY) : ''; }
function cacti_escapeshellcmd(string $value): string { return \escapeshellcmd($value); }
function cacti_escapeshellarg(string $value): string { return \escapeshellarg($value); }
function dsv_log(mixed ...$arguments): void {}
function exec_poll(string $command): string { return '42'; }
function cacti_snmp_session(mixed ...$arguments): never { throw new \RuntimeException('Unexpected device contact'); }
function function_exists(string $name): bool { return $name === 'proc_open' && !empty($GLOBALS['scenario']['disabled']) ? false : \function_exists($name); }
function proc_open(mixed $command, array $descriptors, mixed &$pipes): mixed {
	$GLOBALS['trace']['opens'][] = $command;
	if (!empty($GLOBALS['scenario']['spawn_warning'])) { trigger_error('Injected startup failure', E_USER_WARNING); return false; }
	if (!empty($GLOBALS['scenario']['spawn_failure'])) return false;
	$process = \proc_open($command, $descriptors, $pipes);
	if (is_resource($process)) $GLOBALS['owned'][] = [$process, $pipes];
	return $process;
}
function fwrite(mixed $pipe, string $data): int|false {
	if ($data === "quit\r\n") $GLOBALS['shutting_down'] = true;
	if (!empty($GLOBALS['shutting_down'])) {
		$GLOBALS['trace']['shutdown_writes']++;
		if (!empty($GLOBALS['scenario']['shutdown_failure'])) return 0;
		if (!empty($GLOBALS['scenario']['shutdown_short'])) return \fwrite($pipe, substr($data, 0, 2));
	}
	return \fwrite($pipe, $data);
}
function proc_get_status(mixed $process): array|false {
	return !empty($GLOBALS['scenario']['status_failure']) ? false : \proc_get_status($process);
}
function db_fetch_assoc_prepared(string $sql, array $parameters): array {
	$GLOBALS['trace']['queries'][] = [$sql, $parameters];
	if (($GLOBALS['scenario']['throw_at'] ?? null) === $parameters[1]) throw new \RuntimeException('Injected later database failure');
	return $GLOBALS['scenario']['items'][(string) $parameters[1]] ?? [];
}
function db_fetch_cell_prepared(string $sql, array $parameters): int {
	$GLOBALS['trace']['queries'][] = [$sql, $parameters];
	return count(array_filter($GLOBALS['scenario']['items'][(string) $parameters[1]] ?? [], static fn (array $item): bool => $item['action'] === POLLER_ACTION_SCRIPT_PHP));
}

$functions = file_get_contents($root . '/lib/functions.php');
$poller = file_get_contents($root . '/lib/poller.php');
$remote = file_get_contents($root . '/remote_agent.php');
$validation = file_get_contents($root . '/lib/html_validate.php');
if ($functions === false || $poller === false || $remote === false || $validation === false) throw new \RuntimeException('Cannot read production sources');
$source = '';
foreach (['cacti_sizeof', 'prepare_validate_result', 'strip_alpha', 'is_hexadecimal', 'normalize_poller_multi_value_result'] as $name) $source .= test_php_function_source($functions, $name);
$source .= test_php_function_source($validation, 'input_validate_input_number');
$source .= test_php_function_source($poller, 'exec_poll_php');
$source .= test_php_function_source($remote, 'poll_for_data');
eval('declare(strict_types=0); namespace ' . __NAMESPACE__ . ';' . $source);

$exception = null;
$started = hrtime(true);
ob_start();
try {
	$returned = poll_for_data();
} catch (\Throwable $error) {
	$exception = get_class($error);
}
$output = ob_get_clean();
$elapsed = hrtime(true) - $started;
$events = is_file($directory . '/events.jsonl') ? array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($directory . '/events.jsonl', FILE_IGNORE_NEW_LINES)) : [];
$open = 0;
foreach ($owned as [$process, $pipes]) {
	if (!is_resource($process)) continue;
	$open++;
	// Harness-only cleanup cannot satisfy the snapshot assertions above.
	\proc_terminate($process, 9);
	foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
	\proc_close($process);
}
echo json_encode(['output' => $output, 'exception' => $exception, 'trace' => $trace, 'events' => $events, 'open_processes_before_harness_cleanup' => $open, 'elapsed_ns' => $elapsed, 'php_path' => read_config_option('path_php_binary'), 'script_path' => $config['base_path'] . '/script_server.php', 'injected_file_exists' => file_exists($directory . '/injected')], JSON_THROW_ON_ERROR);
