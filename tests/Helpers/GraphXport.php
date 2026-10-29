<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\GraphXport;

/**
 * Runs the shipped graph_xport.php in a child process. The page is evaluated
 * inside a namespace that supplies header(), because the CLI keeps no header
 * list to read back.
 */
function runExport(array $meta): array
{
    $root = dirname(__DIR__, 2);
    $work = sys_get_temp_dir() . '/kadupul-xport-' . bin2hex(random_bytes(6));
    mkdir($work . '/include', 0700, true);
    mkdir($work . '/lib', 0700, true);

    file_put_contents($work . '/include/auth.php', <<<'PHP'
        <?php
        $_SESSION = array('sess_user_id' => 1);
        function get_filter_request_var($name) {}
        function isempty_request_var($name) { return true; }
        function isset_request_var($name) { return false; }
        function get_request_var($name, $default = '') { return $name === 'local_graph_id' ? 5 : $default; }
        function get_nfilter_request_var($name, $default = '') { return $default; }
        function cacti_session_close() {}
        function cacti_log() {}
        define('POLLER_VERBOSITY_MEDIUM', 3);
        function db_fetch_row_prepared($sql, $params = array()) { return array('base_value' => 1000); }
        function __($text, ...$args) { return vsprintf($text, $args); }
        PHP);
    file_put_contents($work . '/lib/rrd.php', <<<'PHP'
        <?php
        function rrdtool_function_xport($local_graph_id, $rra_id, $data, &$meta, $user = 0) {
            return $GLOBALS['scenario'];
        }
        PHP);
    file_put_contents($work . '/run.php', <<<'PHP'
        <?php
        $GLOBALS['scenario'] = json_decode(stream_get_contents(STDIN), true);
        $source = preg_replace('/^<\?php/', '', file_get_contents($argv[1]), 1);
        $GLOBALS['headers'] = array();
        // Test-only: runs this repository's own page source in a namespace that records header() calls.
        eval('namespace Kadupul\XportProbe; function header($line) { $GLOBALS["headers"][] = $line; } ' . $source);
        print "\n--HEADERS--\n" . json_encode($GLOBALS['headers']);
        PHP);

    try {
        $process = proc_open(
            array(PHP_BINARY, '-d', 'display_errors=stderr', $work . '/run.php', $root . '/graph_xport.php'),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            $work
        );
        fwrite($pipes[0], json_encode(array('meta' => $meta, 'data' => array(array('col1' => '1', 'timestamp' => 0)))));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
    } finally {
        foreach (array('/include/auth.php', '/lib/rrd.php', '/run.php') as $file) {
            unlink($work . $file);
        }
        rmdir($work . '/include');
        rmdir($work . '/lib');
        rmdir($work);
    }

    expect($status)->toBe(0, $stderr)->and($stderr)->toBe('');
    [$body, $headers] = explode("\n--HEADERS--\n", $stdout, 2);

    return array(ltrim($body, "\xEF\xBB\xBF"), json_decode($headers, true));
}

function parseCsv(string $csv): array
{
    return array_map(fn($line) => str_getcsv($line, ',', '"', ''), explode("\n", trim($csv)));
}

function exportMeta(array $override = array()): array
{
    return $override + array(
        'title_cache' => 'Traffic', 'vertical_label' => 'bits per second', 'start' => 0, 'end' => 60, 'step' => 60,
        'rows' => 1, 'local_graph_id' => 5, 'host_id' => 2, 'columns' => 1, 'legend' => array('col1' => 'Inbound'),
    );
}
