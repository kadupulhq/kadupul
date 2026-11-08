<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * #226: rebuilding an aggregate graph passes its members to
 * aggregate_create_update() in their stored sequence, so No Reordering keeps
 * the order the operator chose. The production rebuild runs against SQLite
 * with the aggregate tables it reads.
 */

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

function aggregate_member_rebuild(array $members): array
{
    $root = dirname(__DIR__, 2);
    $aggregate = file_get_contents($root . '/lib/api_aggregate.php');
    $functions = file_get_contents($root . '/lib/functions.php');
    expect($aggregate)->not->toBeFalse()->and($functions)->not->toBeFalse();
    $program = 'namespace AggregateSequenceProbe;$calls=[];'
        . '$db=new \PDO("sqlite::memory:",null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);'
        . '$db->exec("CREATE TABLE aggregate_graphs (id INTEGER PRIMARY KEY, local_graph_id INTEGER, title_format TEXT, graph_template_id INTEGER, gprint_prefix TEXT, gprint_format TEXT, graph_type INTEGER, total INTEGER, total_type INTEGER, total_prefix TEXT, order_type INTEGER)");'
        . '$db->exec("CREATE TABLE aggregate_graphs_graph_item (aggregate_graph_id INTEGER, sequence INTEGER, item_skip TEXT, item_total TEXT, color_template INTEGER, t_graph_type_id TEXT, graph_type_id INTEGER, t_cdef_id TEXT, cdef_id INTEGER)");'
        . '$db->exec("CREATE TABLE aggregate_graphs_items (aggregate_graph_id INTEGER NOT NULL, local_graph_id INTEGER NOT NULL, sequence INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (aggregate_graph_id, local_graph_id))");'
        . '$db->exec("INSERT INTO aggregate_graphs VALUES (3, 90, \'Aggregate\', 1, \'\', \'1\', 0, 0, 0, \'\', 1)");'
        . 'foreach (' . var_export($members, true) . ' as [$graph,$sequence]) { $db->prepare("INSERT INTO aggregate_graphs_items VALUES (3, ?, ?)")->execute([$graph,$sequence]); }'
        . 'function aggregate_graph_fetch_rows(string $sql, array $p = []): array { $q=$GLOBALS["db"]->prepare($sql); $q->execute($p); return $q->fetchAll(); }'
        . 'function aggregate_graph_fetch_row(string $sql, array $p = []): array { return aggregate_graph_fetch_rows($sql,$p)[0] ?? []; }'
        . 'function aggregate_graph_fetch_value(string $sql, array $p = []): mixed { $r=aggregate_graph_fetch_row($sql,$p); return $r===[]?false:reset($r); }'
        . 'function cacti_sizeof($a){return is_countable($a)?count($a):0;}'
        . 'function aggregate_create_update($ag,$members,$attribs){ $GLOBALS["calls"][]=[$ag,$members,$attribs["reorder"]]; return true; }'
        . test_php_function_source($functions, 'array_rekey')
        . test_php_function_source($aggregate, 'push_out_aggregates_operation')
        . '$ok=push_out_aggregates_operation(0,90);echo json_encode([$ok,$GLOBALS["calls"]]);';
    $result = test_php_run($program);
    expect($result['status'])->toBe(0, $result['err'] . $result['out']);

    return json_decode($result['out'], true, 512, JSON_THROW_ON_ERROR);
}

test('a rebuild passes the members in their stored sequence', function (array $members, array $expected) {
    [$ok, $calls] = aggregate_member_rebuild($members);

    expect($ok)->toBeTrue()->and($calls)->toBe([[90, $expected, 1]]);
})->with([
    'reversed against graph id' => [[[5, 2], [6, 1]], [6, 5]],
    'three members out of id order' => [[[5, 3], [6, 1], [7, 2]], [6, 7, 5]],
    'equal sequences fall back to graph id' => [[[8, 0], [4, 0]], [4, 8]],
]);

/**
 * Associate graphs with aggregate 3 over SQLite and return its members in
 * stored sequence order, as [local_graph_id, sequence] pairs.
 *
 * @param array $members existing [local_graph_id, sequence] rows
 * @param array $graphs  graphs to associate, in the order the operator chose
 *
 * @return array
 */
function aggregate_member_associate(array $members, array $graphs): array
{
    $aggregate = file_get_contents(dirname(__DIR__, 2) . '/lib/aggregate.php');
    expect($aggregate)->not->toBeFalse();
    $program = 'namespace AggregateAssociateProbe;'
        . '$db=new \PDO("sqlite::memory:",null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);'
        . '$db->exec("CREATE TABLE aggregate_graphs (id INTEGER PRIMARY KEY, local_graph_id INTEGER, aggregate_template_id INTEGER)");'
        . '$db->exec("CREATE TABLE aggregate_graphs_items (aggregate_graph_id INTEGER NOT NULL, local_graph_id INTEGER NOT NULL, sequence INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (aggregate_graph_id, local_graph_id))");'
        . '$db->exec("INSERT INTO aggregate_graphs VALUES (3, 90, 0)");'
        . 'foreach (' . var_export($members, true) . ' as [$graph,$sequence]) { $db->prepare("INSERT INTO aggregate_graphs_items VALUES (3, ?, ?)")->execute([$graph,$sequence]); }'
        . 'function aggregate_graph_mutation(callable $operation): bool { return $operation(); }'
        . 'function aggregate_graph_fetch_value(string $sql, array $p = []): mixed { $q=$GLOBALS["db"]->prepare($sql); $q->execute($p); $r=$q->fetch(); return $r===false?false:reset($r); }'
        . 'function aggregate_graph_execute(string $sql, array $p = []): bool { if (str_contains($sql, "settings")) { return true; } return $GLOBALS["db"]->prepare($sql)->execute($p); }'
        . 'function push_out_aggregates($template, $graph) { return true; }'
        . test_php_function_source($aggregate, 'api_aggregate_associate')
        . '$ok=api_aggregate_associate(90,' . var_export($graphs, true) . ');'
        . '$rows=$db->query("SELECT local_graph_id, sequence FROM aggregate_graphs_items ORDER BY sequence, local_graph_id")->fetchAll(\PDO::FETCH_NUM);'
        . 'echo json_encode([$ok,$rows]);';
    $result = test_php_run($program);
    expect($result['status'])->toBe(0, $result['err'] . $result['out']);

    return json_decode($result['out'], true, 512, JSON_THROW_ON_ERROR);
}

test('associated graphs follow the last member instead of tying with it', function (array $members, array $graphs, array $expected) {
    [$ok, $rows] = aggregate_member_associate($members, $graphs);

    expect($ok)->toBeTrue()->and($rows)->toBe($expected);
})->with([
    'lower graph id appended after the last member' => [[[50, 1], [60, 2]], [10, 20], [[50, 1], [60, 2], [10, 3], [20, 4]]],
    'first members of an empty aggregate start at one' => [[], [20, 10], [[20, 1], [10, 2]]],
]);
