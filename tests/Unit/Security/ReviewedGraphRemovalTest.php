<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors

function runReviewedGraphRemovalProbe(string $mode, $coverage): string
{
    $root = dirname(__DIR__, 3);
    $directory = sys_get_temp_dir() . '/reviewed-graph-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $program = <<<'PHP'
<?php
$GLOBALS['reviewed_graph_reads'] = [];
$GLOBALS['reviewed_graph_writes'] = [];
$GLOBALS['reviewed_graph_sources'] = [];
function cacti_sizeof($value) { return count($value); }
function db_fetch_assoc($sql) { return array_shift($GLOBALS['reviewed_graph_reads']) ?? []; }
function db_fetch_assoc_prepared($sql, $parameters) { return []; }
function array_rekey($rows, $key, $value) { return array_column($rows, $value, $key); }
function array_to_sql_or($ids, $column) { return $column . ' IN (' . implode(',', $ids) . ')'; }
function api_data_source_remove_multi($ids, $propagate = true, $verify = null) { $GLOBALS['reviewed_graph_sources'][] = [$ids, $propagate]; }
function api_plugin_hook_function($name, $value) {}
function db_execute($sql) { $GLOBALS['reviewed_graph_writes'][] = $sql; }
function set_config_option($key, $value) {}
require $argv[1] . '/lib/api_graph.php';
$ids = [7];
if ($argv[2] === 'scope') {
    $GLOBALS['reviewed_graph_reads'] = [[['local_data_id' => 11], ['local_data_id' => 12]]];
    try { api_delete_graphs($ids, '2', [11], static function () {}); }
    catch (RuntimeException $error) { if ($error->getMessage() !== 'Graph data-source scope changed') throw $error; echo 'REJECTED'; }
} elseif ($argv[2] === 'reviewed') {
    $GLOBALS['reviewed_graph_reads'] = [[['local_data_id' => 11]], [['local_data_id' => 11]], []];
    $verifications = 0;
    api_delete_graphs($ids, '2', [11], static function () use (&$verifications) { $verifications++; });
    if ($verifications !== 1 || $GLOBALS['reviewed_graph_sources'] !== [[[11 => 11], false]] || $GLOBALS['reviewed_graph_writes'] === []) throw new RuntimeException('Reviewed graph deletion was not completed');
    echo 'REMOVED';
} elseif ($argv[2] === 'missing-verifier') {
    try { api_delete_graphs($ids, '2', [11]); }
    catch (RuntimeException $error) { if ($error->getMessage() !== 'Reviewed graph removal requires a dependency verifier') throw $error; echo 'REJECTED'; }
}
PHP;
    file_put_contents($directory . '/probe.php', $program);
    $prefix = '';
    if ($coverage !== null) {
        $prefix = 'define("GRAPH_INPUT_TEST_COVERAGE",true);define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($directory, true) . ');require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    try {
        $process = proc_open([PHP_BINARY, '-d', 'error_reporting=24575', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $prefix . 'require ' . var_export($directory . '/probe.php', true) . ';', $root, $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) throw new RuntimeException($stderr . $stdout);
        expect($stderr)->toBe('');
        if ($coverage !== null) {
            foreach (glob($directory . '/*.coverage') as $report) $coverage->merge(unserialize(file_get_contents($report)));
        }
        return $stdout;
    } finally {
        foreach (glob($directory . '/*') as $file) unlink($file);
        rmdir($directory);
    }
}

test('reviewed graph cleanup rejects widened data and requires its verifier', function ($mode) {
    expect(runReviewedGraphRemovalProbe($mode, $this->getTestResultObject()->getCodeCoverage()))->toBe('REJECTED');
})->with(['scope', 'missing-verifier']);

test('reviewed graph cleanup removes only confirmed sources and verifies after hooks', function () {
    expect(runReviewedGraphRemovalProbe('reviewed', $this->getTestResultObject()->getCodeCoverage()))->toBe('REMOVED');
});
