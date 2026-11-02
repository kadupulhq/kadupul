<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace AggregateTotalLegendFixture;

use PDO;
use RuntimeException;

[$program, $root, $encoded] = $argv;
$scenario = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
require $root . '/tests/Helpers/PhpSource.php';
require $root . '/include/global_constants.php';
$config = ['base_path' => $root];
$database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database->exec('CREATE TABLE graph_templates_item (id INTEGER PRIMARY KEY, local_graph_id INTEGER NOT NULL, sequence INTEGER NOT NULL, cdef_id INTEGER NOT NULL, graph_type_id INTEGER NOT NULL, vdef_id INTEGER NOT NULL)');
$database->exec('CREATE TABLE cdef (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
$database->exec('CREATE TABLE cdef_items (id INTEGER PRIMARY KEY, cdef_id INTEGER NOT NULL, sequence INTEGER NOT NULL, type INTEGER NOT NULL, value TEXT NOT NULL)');
foreach ($scenario['items'] as $row) {
    $statement = $database->prepare('INSERT INTO graph_templates_item VALUES (?,?,?,?,?,?)');
    $statement->execute([$row['id'], $row['local_graph_id'], $row['sequence'], $row['cdef_id'], $row['graph_type_id'], $row['vdef_id']]);
}
$replacement = $scenario['total_type'] === AGGREGATE_TOTAL_TYPE_SIMILAR ? 'SIMILAR_DATA_SOURCES_NODUPS' : 'ALL_DATA_SOURCES_NODUPS';
$original = $scenario['scaled'] ? 'CURRENT_DATA_SOURCE,8,*' : 'CURRENT_DATA_SOURCE';
$total = str_replace('CURRENT_DATA_SOURCE', $replacement, $original);
foreach ([1 => $original, 2 => $total] as $id => $text) {
    $database->prepare('INSERT INTO cdef VALUES (?,?)')->execute([$id, 'fixture ' . $id]);
    $database->prepare('INSERT INTO cdef_items VALUES (?,?,1,6,?)')->execute([$id, $id, $text]);
}

function aggregate_graph_database(): PDO { return $GLOBALS['database']; }
function cacti_log(mixed ...$arguments): void {}
function db_fetch_assoc(string $sql): array { return db_fetch_assoc_prepared($sql); }
function db_fetch_assoc_prepared(string $sql, array $parameters = []): array {
    $statement = aggregate_graph_database()->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_row_prepared(string $sql, array $parameters = []): array { return db_fetch_assoc_prepared($sql, $parameters)[0] ?? []; }
function db_execute_prepared(string $sql, array $parameters = []): bool {
    return aggregate_graph_database()->prepare($sql)->execute($parameters);
}

$api = file_get_contents($root . '/lib/api_aggregate.php');
$aggregate = file_get_contents($root . '/lib/aggregate.php');
$functions = file_get_contents($root . '/lib/functions.php');
$cdef = file_get_contents($root . '/lib/cdef.php');
if ($api === false || $aggregate === false || $functions === false || $cdef === false) throw new RuntimeException('Cannot read aggregate production sources');
$source = test_php_function_source($functions, 'cacti_sizeof');
if (str_contains($api, 'function aggregate_graph_statement(')) {
    foreach (['aggregate_graph_statement', 'aggregate_graph_fetch_rows', 'aggregate_graph_fetch_row', 'aggregate_graph_execute'] as $name) $source .= test_php_function_source($api, $name);
    $source .= test_php_function_source($aggregate, 'aggregate_graph_cdef_text');
} else {
    foreach (['get_cdef', 'get_cdef_item_name'] as $name) $source .= test_php_function_source($cdef, $name);
}
$source .= test_php_function_source($aggregate, 'aggregate_cdef_totalling');
$strict = str_contains($api, 'declare(strict_types=1)') ? '1' : '0';
eval('declare(strict_types=' . $strict . '); namespace ' . __NAMESPACE__ . '; use \PDO; use \PDOStatement; use \Throwable; use \RuntimeException;' . $source);
aggregate_cdef_totalling(7, $scenario['first_total_sequence'], $scenario['total_type']);
$rows = db_fetch_assoc('SELECT * FROM graph_templates_item ORDER BY id');
echo json_encode(['items' => $rows, 'cdefs' => [1 => $original, 2 => $total]], JSON_THROW_ON_ERROR);
