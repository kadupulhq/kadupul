<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace PollerReplicationSequence;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

$root = dirname(__DIR__, 4);
$source = file_get_contents($root . '/lib/poller.php');
if ($source === false) {
    throw new \RuntimeException('Cannot read the collector synchronizer.');
}
eval('namespace ' . __NAMESPACE__ . ';' . str_replace('__DIR__', var_export($root . '/lib', true), \test_php_function_source($source, 'replicate_out')));

const FIXTURE = __DIR__ . '/../../../Fixtures/poller-replication/replicate-out-sequence.json';

function record(array $event): void
{
    $GLOBALS['sync_sequence']['events'][] = $event;
}

function sql_id(string $sql): int
{
    $state = &$GLOBALS['sync_sequence'];
    $id = array_search($sql, $state['sql'], true);
    if ($id === false) {
        $state['sql'][] = $sql;
        $id = count($state['sql']) - 1;
    }

    return $id;
}

function connection(mixed $connection): string
{
    return $connection === false ? 'local' : (is_string($connection) ? $connection : get_debug_type($connection));
}

function rows(string $sql): array|false
{
    preg_match('/FROM\s+([a-z_]+)/', $sql, $table);
    if ($GLOBALS['sync_sequence']['failure'] === 'read-' . ($table[1] ?? '')) {
        return false;
    }

    // Tag rows with their query so each copy proves which read it received.
    return array(array('sql' => sql_id($sql)));
}

function db_fetch_assoc(string $sql, bool $log = true, mixed $connection = false): array|false
{
    record(array('fetch_assoc', sql_id($sql), connection($connection)));

    return rows($sql);
}

function db_fetch_assoc_prepared(string $sql, array $params, bool $log = true, mixed $connection = false): array|false
{
    record(array('fetch_assoc_prepared', sql_id($sql), $params, connection($connection)));

    return rows($sql);
}

function db_fetch_row_prepared(string $sql, array $params, bool $log = true, mixed $connection = false): array|false
{
    record(array('fetch_row_prepared', sql_id($sql), $params, connection($connection)));

    return $GLOBALS['sync_sequence']['failure'] === 'statistics-read' ? false : array('snmp' => 3, 'script' => 2, 'server' => 1);
}

function db_fetch_cell(string $sql, string $column = '', bool $log = true, mixed $connection = false): int|false|null
{
    record(array('fetch_cell', sql_id($sql), $column, $log, connection($connection)));

    return match ($GLOBALS['sync_sequence']['failure']) {
        'cutoff-read' => false,
        'no-cutoff' => null,
        default => 1700000000,
    };
}

function db_execute(string $sql, bool $log = true, mixed $connection = false): bool
{
    record(array('execute', sql_id($sql), $log, connection($connection)));

    return $GLOBALS['sync_sequence']['failure'] !== 'reindex-cleanup';
}

function db_execute_prepared(string $sql, array $params, bool $log = true, mixed $connection = false): bool
{
    record(array('execute_prepared', sql_id($sql), $params, $log, connection($connection)));
    $stage = str_contains($sql, 'last_sync=') ? 'completion' : (str_contains($sql, 'SET snmp') ? 'statistics-write' : (str_contains($sql, 'SET last_updated') ? 'cutoff-write' : (str_starts_with(ltrim($sql), 'DELETE') ? 'cutoff-delete' : 'retry-mark')));

    return $GLOBALS['sync_sequence']['failure'] !== $stage;
}

function replicate_out_table(mixed $connection, mixed &$data, string $table, int $poller, bool $truncate = true, mixed $exclude = false, int $level = 0): bool
{
    record(array('copy', $table, $poller, $truncate, $exclude, connection($connection), is_array($data) ? array_column($data, 'sql') : $data, func_num_args()));

    return is_array($data) && $GLOBALS['sync_sequence']['failure'] !== $table;
}

function replicate_table_to_poller(mixed $connection, mixed &$data, string $table): bool
{
    record(array('upsert', $table, connection($connection), is_array($data) ? array_column($data, 'sql') : $data, func_num_args()));

    return is_array($data) && $GLOBALS['sync_sequence']['failure'] !== $table;
}

function poller_connect_to_remote(int $poller): string|false
{
    record(array('connect', $poller));

    return $GLOBALS['sync_sequence']['failure'] === 'connect' ? false : 'remote';
}

function data_source_profile_reference_guards_available(mixed $connection): bool
{
    record(array('guards', connection($connection)));

    return $GLOBALS['sync_sequence']['failure'] !== 'guards';
}

function data_source_profile_reference_index_available(mixed $connection): bool
{
    record(array('index', connection($connection)));

    return true;
}

function cacti_sizeof(mixed $value): int
{
    return is_countable($value) ? count($value) : 0;
}

function __($message): string
{
    return $message;
}

function api_plugin_hook_function(string $name, array $parameters): array
{
    record(array('hook', $name, $parameters));

    return $parameters;
}

function cacti_log(string $message, mixed ...$args): void
{
    record(array('cacti_log', $message, $args));
}

function replicate_log(string $message, mixed ...$args): void
{
    record(array('replicate_log', $message, $args));
}

function raise_message(string $name, mixed ...$args): void
{
    record(array('raise_message', $name, $args));
}

foreach (array('POLLER_VERBOSITY_LOW' => 2, 'MESSAGE_LEVEL_ERROR' => 1) as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

/** @return array{result: bool, events: list<array<mixed>>, sql: list<string>} */
function replication_trace(string $class, string $failure, bool $web): array
{
    $hadConfig = array_key_exists('config', $GLOBALS);
    $savedConfig = $GLOBALS['config'] ?? null;
    $GLOBALS['config'] = array('is_web' => $web);
    $GLOBALS['sync_sequence'] = array('failure' => $failure, 'events' => array(), 'sql' => array());
    try {
        $result = replicate_out(2, $class);

        return array('result' => $result, 'events' => $GLOBALS['sync_sequence']['events'], 'sql' => $GLOBALS['sync_sequence']['sql']);
    } finally {
        if ($hadConfig) {
            $GLOBALS['config'] = $savedConfig;
        } else {
            unset($GLOBALS['config']);
        }
        unset($GLOBALS['sync_sequence']);
    }
}

/** Replace statement ids with their text so the comparison does not depend on first-use order. */
function resolved(array $trace): array
{
    $events = array();
    foreach ($trace['events'] as $event) {
        if (in_array($event[0], array('fetch_assoc', 'fetch_assoc_prepared', 'fetch_row_prepared', 'fetch_cell', 'execute', 'execute_prepared'), true)) {
            $event[1] = $trace['sql'][$event[1]];
        } elseif ($event[0] === 'copy' && is_array($event[6])) {
            $event[6] = array_map(fn(int $id): string => $trace['sql'][$id], $event[6]);
        } elseif ($event[0] === 'upsert' && is_array($event[3])) {
            $event[3] = array_map(fn(int $id): string => $trace['sql'][$id], $event[3]);
        }
        $events[] = $event;
    }

    return array('result' => $trace['result'], 'events' => $events);
}

/** @return array<string, array{string, string, bool}> */
function replication_cases(): array
{
    $cases = array();
    foreach (array('all', 'data', 'settings', 'auth', 'plugins', 'unknown') as $class) {
        $cases[$class . '-web'] = array($class, '', true);
        $cases[$class . '-cli'] = array($class, '', false);
    }
    $tables = explode(' ', 'settings automation_graph_rule_items automation_graph_rules automation_match_rule_items automation_snmp automation_snmp_items automation_templates automation_tree_rule_items automation_tree_rules data_input host_template host_template_graph host_template_snmp_query snmp_query data_input_fields poller plugin_config plugin_hooks plugin_realms user_auth user_auth_group user_auth_group_members user_auth_group_perms user_auth_group_realm user_auth_realm user_auth_row_cache user_domains user_domains_ldap host_snmp_query poller_command host host_snmp_cache poller_reindex poller_item data_local graph_local data_template_data data_template_rrd graph_templates_item data_input_data');
    foreach ($tables as $table) {
        $cases['copy-' . $table] = array('all', $table, true);
        $cases['read-' . $table] = array('all', 'read-' . $table, true);
    }
    foreach (array('plugin_config', 'plugin_realms') as $table) {
        $cases['plugins-copy-' . $table] = array('plugins', $table, true);
    }
    foreach (array('user_auth', 'user_domains_ldap') as $table) {
        $cases['auth-copy-' . $table] = array('auth', $table, true);
        $cases['auth-cli-copy-' . $table] = array('auth', $table, false);
    }
    foreach (array('retry-mark', 'connect', 'guards', 'reindex-cleanup', 'cutoff-read', 'no-cutoff', 'cutoff-write', 'cutoff-delete', 'statistics-read', 'statistics-write', 'completion') as $failure) {
        $cases[$failure] = array('all', $failure, true);
        $cases[$failure . '-cli'] = array('all', $failure, false);
    }
    $cases['data-copy-data_template_data-cli'] = array('data', 'data_template_data', false);

    return $cases;
}

/**
 * Expand a recorded case. A failure case stores only where it leaves the
 * successful run of the same class, which keeps the shared prefix exact.
 */
function expected(array $fixture, string $name): array
{
    $case = $fixture['cases'][$name];
    $events = isset($case['base']) ? array_merge(array_slice($fixture['cases'][$case['base']]['events'], 0, $case['shared']), $case['tail']) : $case['events'];

    return resolved(array('result' => $case['result'], 'events' => $events, 'sql' => $fixture['sql']));
}

test('collector synchronization issues the recorded statement sequence', function (string $class, string $failure, bool $web) {
    $fixture = json_decode((string) file_get_contents(FIXTURE), true, 512, JSON_THROW_ON_ERROR);
    $name = $class . '|' . $failure . '|' . ($web ? 'web' : 'cli');
    expect($fixture['cases'])->toHaveKey($name);
    expect(resolved(replication_trace($class, $failure, $web)))->toBe(expected($fixture, $name));
})->with(replication_cases());
