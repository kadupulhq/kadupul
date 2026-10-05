<?php

declare(strict_types=1);

/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
*/

namespace EffectiveGraphPolicyNativeTest;

require_once dirname(__DIR__, 3).'/Helpers/PhpSource.php';
$source = file_get_contents(dirname(__DIR__, 4).'/lib/auth.php');
if ($source === false) { throw new \RuntimeException('Unable to read native graph authorization'); }
foreach (['get_permission_string', 'get_policy_join_select', 'get_policy_where'] as $name) {
    eval('namespace '.__NAMESPACE__.';'.test_php_function_source($source, $name));
}
function read_config_option($name) { return $GLOBALS['effective_graph_method']; }
function read_user_setting(...$arguments) { return false; }
function get_request_var($name) { return 41; }
function __esc($message, ...$arguments) { return htmlspecialchars($arguments ? vsprintf($message, $arguments) : $message, ENT_QUOTES); }
function __($message) { return $message; }

function connection(): \PDO {
    if (!getenv('AUTH_POLICY_NATIVE_DB')) { return new \PDO('sqlite::memory:'); }
    static $db;
    if ($db instanceof \PDO) { return $db; }
    $host = getenv('BOOST_DB_HOST') ?: '127.0.0.1';
    $port = getenv('BOOST_DB_PORT') ?: '3306';
    $name = getenv('AUTH_POLICY_DB_NAME') ?: (getenv('BOOST_DB_NAME') ?: 'cacti_boost_contract');
    $connection = new \PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", getenv('BOOST_DB_USER') ?: 'root', getenv('BOOST_DB_PASSWORD') ?: '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    // MySQL cannot reopen a temporary table in these production joins. CI
    // provisions an empty dedicated schema with grants limited to that schema.
    $schema = getenv('AUTH_POLICY_DB_NAME');
    if (!$schema) {
        $schema = 'kadupul_auth_policy_'.bin2hex(random_bytes(12));
        $connection->exec('CREATE DATABASE `'.$schema.'`');
        $connection->exec('USE `'.$schema.'`');
    }
    $created = [];
    register_shutdown_function(static function () use ($connection, &$created, $schema) {
        foreach (array_reverse($created) as $table) { $connection->exec('DROP TABLE `'.$table.'`'); }
        if (!getenv('AUTH_POLICY_DB_NAME')) { $connection->exec('DROP DATABASE `'.$schema.'`'); }
    });
    foreach (tableDefinitions() as $table => $columns) {
        $connection->exec('CREATE TABLE '.$table.' ('.$columns.')');
        $created[] = $table;
    }
    $db = $connection;
    return $db;
}
function tableDefinitions(): array {
    return [
        'graph_local' => 'id INTEGER, host_id INTEGER, graph_template_id INTEGER',
        'host' => 'id INTEGER, disabled VARCHAR(2)',
        'user_auth_perms' => 'user_id INTEGER, item_id INTEGER, type INTEGER',
        'user_auth_group_perms' => 'group_id INTEGER, item_id INTEGER, type INTEGER',
    ];
}
function createTables(\PDO $db): void {
    foreach (tableDefinitions() as $table => $columns) { $db->exec('CREATE TABLE '.$table.' ('.$columns.')'); }
}
function evaluate(array $policies, array $exceptions, int $method): array {
    $db = connection();
    if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        createTables($db);
    } else {
        foreach (['graph_local', 'host', 'user_auth_perms', 'user_auth_group_perms'] as $table) { $db->exec('DELETE FROM '.$table); }
    }
    $db->exec("INSERT INTO host VALUES (7, ''); INSERT INTO graph_local VALUES (11, 7, 13)");
    foreach ($exceptions as [$policy, $type]) {
        $p = $policies[$policy];
        $table = $p['type'] === 'user' ? 'user_auth_perms' : 'user_auth_group_perms';
        $item = [1 => 11, 3 => 7, 4 => 13][$type];
        $db->prepare('INSERT INTO '.$table.' VALUES (?, ?, ?)')->execute([$p['id'], $item, $type]);
    }
    $joins = get_policy_join_select($policies);
    $from = ' FROM graph_local AS gl JOIN host AS h ON h.id=gl.host_id ';
    $graph = $db->query('SELECT gl.id, h.disabled, '.$joins['sql_select'].$from.$joins['sql_join'])->fetch(\PDO::FETCH_ASSOC);
    $allowed = $db->query('SELECT gl.id'.$from.get_policy_where($method, $policies, ''))->fetchColumn();
    $GLOBALS['effective_graph_method'] = $method;
    return [$allowed !== false, get_permission_string($graph, $policies)];
}

$cases = [];
foreach ([1, 2, 3, 4] as $method) {
    foreach (['user', 'group'] as $type) {
        foreach ([1, 2] as $graphDefault) {
            foreach ([1, 2] as $deviceDefault) {
                foreach ([1, 2] as $templateDefault) {
                    foreach ([false, true] as $graphException) {
                        foreach ([false, true] as $deviceException) {
                            foreach ([false, true] as $templateException) {
                                $policy = ['id' => 41, 'type' => $type, 'name' => 'fixture', 'policy_graphs' => $graphDefault, 'policy_hosts' => $deviceDefault, 'policy_graph_templates' => $templateDefault];
                                $exceptions = [];
                                foreach ([1 => $graphException, 3 => $deviceException, 4 => $templateException] as $kind => $present) { if ($present) { $exceptions[] = [0, $kind]; } }
                                $cases[] = [[$policy], $exceptions, $method];
                            }
                        }
                    }
                }
            }
        }
    }
}
$cases[] = [[['id' => 41, 'type' => 'user', 'name' => 'viewer', 'policy_graphs' => 2, 'policy_hosts' => 2, 'policy_graph_templates' => 2], ['id' => 51, 'type' => 'group', 'name' => 'operators', 'policy_graphs' => 2, 'policy_hosts' => 2, 'policy_graph_templates' => 2]], [[1, 3], [1, 4]], 2];
$cases[] = [$cases[count($cases)-1][0], [[1, 3]], 2];

test('permission display agrees with executed production SQL enforcement', function ($policies, $exceptions, $method) {
    [$allowed, $html] = evaluate($policies, $exceptions, $method);
    expect(str_contains($html, "class='accessGranted'"))->toBe($allowed)
        ->and(str_contains($html, "class='accessRestricted'"))->toBe(!$allowed)->and($html)->not->toBe('Unknown');
})->with($cases);

test('restrictive tooltips keep Device and Template reasons in the correct bucket', function ($exceptions, $expectedGranted, $bucket) {
    $policies = [['id' => 41, 'type' => 'user', 'name' => 'viewer', 'policy_graphs' => 2, 'policy_hosts' => 2, 'policy_graph_templates' => 2]];
    [$allowed, $html] = evaluate($policies, $exceptions, 2);
    expect($allowed)->toBe($expectedGranted)->and($html)->toContain($bucket.'Device+Template:(User)');
})->with([
    [[[0, 3], [0, 4]], true, 'Granted By: '],
    [[[0, 3]], false, 'Restricted By: Graph:(User), '],
    [[[0, 1], [0, 3]], true, 'Restricted By: '],
]);
