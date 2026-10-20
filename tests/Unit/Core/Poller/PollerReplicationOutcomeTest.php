<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace PollerReplicationOutcome;

use PDO;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

$root = dirname(__DIR__, 4);
$source = file_get_contents($root . '/lib/poller.php');
if ($source === false) {
    throw new \RuntimeException('Cannot read the collector synchronizer.');
}
// Resolve the production include relative to its source file, not this extracted function.
eval('namespace ' . __NAMESPACE__ . ';' . str_replace('__DIR__', var_export($root . '/lib', true), \test_php_function_source($source, 'replicate_out')));

function db_execute_prepared(string $sql, array $params, bool $log = true, mixed $connection = false): bool
{
    $state = &$GLOBALS['sync_outcome'];
    $state['writes'][] = $sql;
    $stage = str_contains($sql, 'last_sync=') ? 'completion' : (str_contains($sql, 'SET snmp') ? 'statistics-write' : (str_contains($sql, 'last_updated') ? 'cutoff-write' : 'retry-mark'));
    if ($state['failure'] === $stage || ($state['failure'] === 'cutoff-delete' && str_starts_with(ltrim($sql), 'DELETE'))) {
        return false;
    }
    if (str_contains($sql, 'UPDATE poller SET')) {
        return $state['db']->prepare(str_replace('NOW()', "'confirmed sync'", $sql))->execute($params);
    }

    return true;
}

function db_execute(string $sql, bool $log = true, mixed $connection = false): bool
{
    return $GLOBALS['sync_outcome']['failure'] !== 'reindex-cleanup';
}

function db_fetch_assoc(string $sql): array|false
{
    preg_match('/FROM\s+([a-z_]+)/', $sql, $table);

    return $GLOBALS['sync_outcome']['failure'] === 'read-' . ($table[1] ?? '') ? false : [];
}

function db_fetch_assoc_prepared(string $sql, array $params): array|false
{
    return db_fetch_assoc($sql);
}

function db_fetch_row_prepared(string $sql, array $params): array|false
{
    return $GLOBALS['sync_outcome']['failure'] === 'statistics-read' ? false : ['snmp' => 0, 'script' => 0, 'server' => 0];
}

function db_fetch_cell(string $sql, string $column = '', bool $log = true, mixed $connection = false): int|false|null
{
    return match ($GLOBALS['sync_outcome']['failure']) {
        'cutoff-read' => false,
        'cutoff-write', 'cutoff-delete' => 100,
        default => null,
    };
}

function replicate_out_table(mixed $connection, mixed &$data, string $table, int $poller, bool $truncate = true, mixed $exclude = false): bool
{
    $GLOBALS['sync_outcome']['tables'][] = $table;

    return is_array($data) && $GLOBALS['sync_outcome']['failure'] !== $table;
}

function replicate_table_to_poller(mixed $connection, mixed &$data, string $table): bool
{
    return replicate_out_table($connection, $data, $table, 2);
}

function poller_connect_to_remote(int $poller): PDO
{
    return $GLOBALS['sync_outcome']['db'];
}

function data_source_profile_reference_guards_available(mixed $connection): bool
{
    return true;
}
function data_source_profile_reference_index_available(mixed $connection): bool
{
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
    return $parameters;
}
function cacti_log(string $message, mixed ...$args): void
{
    $GLOBALS['sync_outcome']['logs'][] = $message;
}
function replicate_log(string $message, mixed ...$args): void
{
    cacti_log($message);
}
function raise_message(string $name, mixed ...$args): void
{
    $GLOBALS['sync_outcome']['messages'][] = $name;
}

foreach (['POLLER_VERBOSITY_LOW' => 2, 'MESSAGE_LEVEL_ERROR' => 1] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

test('collector synchronization acknowledges only confirmed work', function (string $class, string $failure, bool $web) {
    $hadConfig = array_key_exists('config', $GLOBALS);
    $savedConfig = $GLOBALS['config'] ?? null;
    $partial = !in_array($class, ['all', 'data'], true);
    $db = new PDO('sqlite::memory:');
    $db->exec('CREATE TABLE poller (id INTEGER PRIMARY KEY, requires_sync TEXT, last_sync TEXT)');
    $db->prepare("INSERT INTO poller VALUES (2,?,'previous sync')")->execute([$partial ? 'on' : '']);
    $GLOBALS['config'] = ['is_web' => $web];
    $GLOBALS['sync_outcome'] = ['db' => $db, 'failure' => $failure, 'writes' => [], 'logs' => [], 'messages' => [], 'tables' => []];
    try {
        $success = $failure === '';
        expect(replicate_out(2, $class))->toBe($success);
        $state = $GLOBALS['sync_outcome'];
        $poller = $db->query('SELECT * FROM poller')->fetch(PDO::FETCH_ASSOC);
        $complete = $success && in_array($class, ['all', 'data'], true);
        expect($poller['last_sync'])->toBe($complete ? 'confirmed sync' : 'previous sync');
        expect($poller['requires_sync'])->toBe($partial || (!$success && $failure !== 'retry-mark') ? 'on' : '');
        expect(in_array('poller_sync', $state['messages'], true))->toBe($success && $web && $class !== 'plugins');
        expect(str_contains(implode('\n', $state['logs']), 'Synchronization of Poller 2 completed'))->toBe($success && $web && $class !== 'plugins');
        if (!$success && $web && $class !== 'plugins') {
            expect($state['messages'])->toContain('poller_sync_failed');
        }
        if (in_array($failure, $state['tables'], true)) {
            expect(end($state['tables']))->toBe($failure);
        }
    } finally {
        if ($hadConfig) {
            $GLOBALS['config'] = $savedConfig;
        } else {
            unset($GLOBALS['config']);
        }
        unset($GLOBALS['sync_outcome']);
    }
})->with((function (): array {
    $cases = [];
    // Inventory of the original full-sync table handoffs, including its settings upsert.
    $tables = explode(' ', 'settings automation_graph_rule_items automation_graph_rules automation_match_rule_items automation_snmp automation_snmp_items automation_templates automation_tree_rule_items automation_tree_rules data_input host_template host_template_graph host_template_snmp_query snmp_query data_input_fields poller plugin_config plugin_hooks plugin_realms user_auth user_auth_group user_auth_group_members user_auth_group_perms user_auth_group_realm user_auth_realm user_auth_row_cache user_domains user_domains_ldap host_snmp_query poller_command host host_snmp_cache poller_reindex poller_item data_local graph_local data_template_data data_template_rrd graph_templates_item data_input_data');
    foreach ($tables as $table) {
        $cases['copy-' . $table] = ['all', $table, true];
    }
    foreach (['retry-mark', 'completion', 'statistics-read', 'statistics-write', 'reindex-cleanup', 'cutoff-read', 'cutoff-write', 'cutoff-delete', 'read-settings', 'read-data_input', 'read-host', 'read-data_input_data'] as $failure) {
        $cases[$failure] = ['all', $failure, true];
    }
    foreach (['all', 'data', 'settings', 'auth', 'plugins'] as $class) {
        $cases[$class . '-success'] = [$class, '', true];
    }
    $cases['settings-failure'] = ['settings', 'settings', true];
    $cases['auth-failure'] = ['auth', 'user_auth', true];
    $cases['plugins-failure'] = ['plugins', 'plugin_config', true];
    $cases['cli-failure'] = ['all', 'plugin_config', false];
    $cases['cli-success'] = ['all', '', false];

    return $cases;
})());
