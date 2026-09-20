<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('aggregate item replacement binds values and preserves unrelated rows', function ($table, $payload) {
    $root = dirname(__DIR__, 3);
    $directory = sys_get_temp_dir() . '/aggregate-query-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $program = <<<'PHP'
require $argv[1] . '/include/global_constants.php';
require $argv[1] . '/lib/api_aggregate.php';
$table = $argv[2];
$id = $table === 'aggregate_graphs_graph_item' ? 'aggregate_graph_id' : 'aggregate_template_id';
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE $table ($id INTEGER, graph_templates_item_id INTEGER, sequence INTEGER, color_template INTEGER, t_graph_type_id TEXT, graph_type_id INTEGER, t_cdef_id TEXT, cdef_id INTEGER, item_skip TEXT, item_total TEXT)");
$db->exec("INSERT INTO $table ($id, graph_templates_item_id) VALUES (1, 10), (2, 20)");
$queries = array();
function cacti_log(...$args) {}
function db_execute_prepared($sql, $parameters) {
    global $db, $queries;
    $queries[] = array($sql, $parameters);
    return $db->prepare($sql)->execute($parameters);
}
function db_execute(...$args) { throw new RuntimeException('Unbound SQL execution'); }
function db_qstr(...$args) { throw new RuntimeException('Values must remain bound'); }
$item = array($id => $argv[3], 'graph_templates_item_id' => 11,
    'sequence' => '3', 'color_template' => '4', 'graph_type_id' => '5', 'cdef_id' => '6',
    't_graph_type_id' => "on'); DELETE FROM $table; --",
    't_cdef_id' => "quote'\\value", 'item_skip' => 'on', 'item_total' => '');
$second = array($id => 1, 'graph_templates_item_id' => 12);
$saved = aggregate_graph_items_save(array($item, $second), $table);
echo json_encode(array('saved' => $saved, 'queries' => $queries,
    'rows' => $db->query("SELECT * FROM $table ORDER BY graph_templates_item_id")->fetchAll(PDO::FETCH_ASSOC)));
PHP;
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    if ($coverage !== null) {
        $program = 'define("AGGREGATE_QUERY_TEST_COVERAGE", true);'
            . 'define("RRD_TEST_COVERAGE_DIRECTORY", $argv[4]);'
            . 'require $argv[1] . "/tests/Fixtures/rrd-process-coverage.php";' . $program;
    }
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=' . $root,
            '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $program,
            $root, $table, $payload, $directory), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start aggregate query probe');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException($stderr . $stdout);
        }
        expect($stderr)->toBe('');
        $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        expect($result['saved'])->toBeTrue();
        $id = $table === 'aggregate_graphs_graph_item' ? 'aggregate_graph_id' : 'aggregate_template_id';
        expect($result['queries'][0])->toBe(array("DELETE FROM $table WHERE $id = ?", array(1)));
        expect(substr_count($result['queries'][1][0], '?'))->toBe(20);
        expect($result['queries'][1][0])->not->toContain('DELETE');
        expect($result['rows'])->toHaveCount(3);
        expect(array_column($result['rows'], 'graph_templates_item_id'))->toBe(array(11, 12, 20));
        expect($result['rows'][0]['t_graph_type_id'])->toBe("on'); DELETE FROM $table; --");
        expect($result['rows'][0]['t_cdef_id'])->toBe("quote'\\value");
        expect($result['rows'][0]['sequence'])->toBe(3);
        expect($result['rows'][1]['sequence'])->toBe(0);
        expect($result['rows'][2][$id])->toBe(2);
        if ($coverage !== null) {
            foreach (glob($directory . '/*.coverage') as $report) {
                $coverage->merge(unserialize(file_get_contents($report)));
            }
        }
    } finally {
        foreach (glob($directory . '/*.coverage') as $report) {
            unlink($report);
        }
        rmdir($directory);
    }
})->with(array('aggregate_graphs_graph_item', 'aggregate_graph_templates_item'))
    ->with(array('1', '1 OR 1=1', '1; DROP TABLE aggregate_graphs_graph_item; --'));
