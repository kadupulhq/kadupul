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
