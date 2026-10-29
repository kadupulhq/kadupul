<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\Security\TitleAndExportOutput;

use DOMDocument;
use RuntimeException;

/*
 * The page title is printed into <title>, and a graph title is written into
 * the CSV export and its suggested file name. Upstream Cacti #8164 and #8165
 * (GHSA-v99q-57w7-mpmg, GHSA-hh7r-464j-rf39).
 */

$root = dirname(__DIR__, 3);

require_once $root . '/tests/Helpers/PhpSource.php';

$html = file_get_contents($root . '/lib/html.php');
if ($html === false) {
    throw new RuntimeException('Unreadable lib/html.php');
}
foreach (array('html_escape_charset', 'html_escape', 'html_legacy_js_registry', 'html_legacy_js_global', 'html_legacy_js_state', 'html_legacy_js_tags', 'html_legacy_js_head', 'html_common_header') as $function) {
    // Test-only: loads this repository's own function source into a private namespace.
    eval('namespace ' . __NAMESPACE__ . '; ' . \test_php_function_source($html, $function));
}

// Test-only: the shipped class is a static helper; only its attribute output matters here.
eval('namespace ' . __NAMESPACE__ . '; class CactiSecureHeaders { public static function getNonceAttribute() { return ""; } }');

function get_selected_theme()
{
    return 'modern';
}

function html_icon_registry()
{
    return new class () {
        public function forTheme($theme)
        {
            return array();
        }
    };
}

function read_user_setting($name, $default = '')
{
    return $default;
}

function is_view_allowed($name)
{
    return false;
}

function is_realm_allowed($realm)
{
    return false;
}

function read_config_option($name, $force = false)
{
    return '';
}

function get_md5_include_css($path)
{
    return '';
}

function get_md5_include_js($path, $defer = false)
{
    return '';
}

function api_plugin_hook($hook) {}

function __esc($text, ...$args)
{
    return htmlspecialchars(vsprintf($text, $args), ENT_QUOTES);
}

test('the page title is printed as text', function (string $title) {
    $GLOBALS['config'] = array('url_path' => '/');

    ob_start();
    try {
        html_common_header($title);
    } finally {
        $head = ob_get_clean();
    }

    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<!doctype html><html><head><meta charset="UTF-8">' . $head . '</head><body></body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $titles = $document->getElementsByTagName('title');
    expect($titles->length)->toBe(1)
        ->and($titles->item(0)->textContent)->toBe($title)
        ->and($document->getElementsByTagName('img')->length)->toBe(0);
})->with(array(
    'markup' => array('</title><img src=x onerror=alert(1)>'),
    'quotes and ampersand' => array('Devices "a" & \'b\''),
    'plain' => array('Graphs'),
));

// The three headers are templates that need the whole page bootstrap, so this is a source check.
test('the ajax title element in each page header is encoded', function (string $file) {
    $source = file_get_contents(dirname(__DIR__, 3) . '/include/' . $file);

    expect($source)->toContain('<title><?php print html_escape($page_title);?></title>')
        ->and($source)->not->toContain('<title><?php print $page_title;?></title>');
})->with(array('top_header.php', 'top_general_header.php', 'top_graph_header.php'));

/**
 * Runs the shipped graph_xport.php in a child process. The page is evaluated
 * inside a namespace that supplies header(), because the CLI keeps no header
 * list to read back.
 */
function runExport(array $meta): array
{
    $root = dirname(__DIR__, 3);
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
        proc_close($process);
    } finally {
        foreach (array('/include/auth.php', '/lib/rrd.php', '/run.php') as $file) {
            unlink($work . $file);
        }
        rmdir($work . '/include');
        rmdir($work . '/lib');
        rmdir($work);
    }

    expect($stderr)->toBe('');
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

test('the export guards title, vertical label and legend cells against spreadsheet formulas', function (string $field, string $cell) {
    $meta = exportMeta();
    if ($field === 'legend') {
        $meta['legend']['col1'] = $cell;
    } else {
        $meta[$field] = $cell;
    }

    [$csv] = runExport($meta);
    $rows = parseCsv($csv);

    $value = $field === 'title_cache' ? $rows[0][1] : ($field === 'vertical_label' ? $rows[1][1] : $rows[array_key_last($rows) - 1][1]);

    expect($value)->toBe("'" . $cell);
})->with(array(
    'title' => array('title_cache', '=HYPERLINK("http://example.invalid","x")'),
    'label plus' => array('vertical_label', '+1+1'),
    'legend at' => array('legend', '@SUM(1+1)'),
    'legend minus' => array('legend', '-2+3'),
));

test('the export leaves ordinary cells and embedded quotes intact', function () {
    [$csv] = runExport(exportMeta(array('title_cache' => 'Say "hi", ok', 'legend' => array('col1' => "In\nbound"))));
    $rows = parseCsv($csv);

    expect($rows[0][1])->toBe('Say "hi", ok')
        ->and($rows[1][1])->toBe('bits per second')
        ->and($rows[array_key_last($rows) - 1][1])->toBe('In bound');
});

test('the suggested file name cannot leave the quoted header value', function () {
    [, $headers] = runExport(exportMeta(array('title_cache' => "a\"; x=\"b\\\r\nSet-Cookie: c=1")));

    $disposition = array_values(array_filter($headers, fn($line) => str_starts_with($line, 'Content-Disposition:')));

    expect($disposition)->toHaveCount(1)
        ->and($disposition[0])->toBe('Content-Disposition: attachment; filename="a; x=bSet-Cookie: c=1.csv"');
});
