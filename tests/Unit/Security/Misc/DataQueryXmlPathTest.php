<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * The data query edit page and the template import preview report whether a
 * data query XML file exists. They must answer only for files under
 * resource/, which is all get_data_query_array() will read, or they tell an
 * administrator whether any path on the server exists (GHSA-2x86-jpm8-9vgp).
 * Each case runs the shipped function in a child process against a temporary
 * install tree.
 */

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function dq_path_child(string $program, array $input = array(), ?string $cwd = null): array
{
    $pipes = array();
    $process = proc_open(
        array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', '-r', $program),
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        $cwd
    );
    fwrite($pipes[0], json_encode($input));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    expect($stderr)->toBe('');

    return json_decode($stdout, true) ?? array('raw' => $stdout);
}

function dq_path_functions(string $file, array $names): string
{
    $source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);
    $code = '';

    foreach ($names as $name) {
        $code .= test_php_function_source($source, $name) . "\n";
    }

    return $code;
}

/** A disposable install tree with files inside and outside resource/. */
function dq_path_tree(): string
{
    $base = sys_get_temp_dir() . '/kadupul-dqpath-' . bin2hex(random_bytes(6));
    mkdir($base . '/resource/snmp_queries', 0700, true);
    mkdir($base . '/resource/dir.xml', 0700);
    file_put_contents($base . '/resource/snmp_queries/inside.xml', '<interface></interface>');
    file_put_contents($base . '/outside.xml', '<interface></interface>');
    symlink($base . '/outside.xml', $base . '/resource/snmp_queries/escape.xml');

    return realpath($base);
}

function dq_path_tree_remove(string $base): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($base);
}

function dq_path_cases(): array
{
    return array(
        'file under resource' => array('<path_cacti>/resource/snmp_queries/inside.xml', true),
        'existing file outside resource' => array('<path_cacti>/outside.xml', false),
        'traversal out of resource' => array('<path_cacti>/resource/../outside.xml', false),
        'symlink under resource to a file outside' => array('<path_cacti>/resource/snmp_queries/escape.xml', false),
        'missing file under resource' => array('<path_cacti>/resource/snmp_queries/missing.xml', false),
        'directory under resource' => array('<path_cacti>/resource/dir.xml', false),
        'NUL byte' => array("<path_cacti>/resource/snmp_queries/inside.xml\0.txt", false),
    );
}

function dq_path_edit_page(string $base, string $xml_path): string
{
    $program = '$GLOBALS["input"] = json_decode(stream_get_contents(STDIN), true);'
        . '$config = array("base_path" => $GLOBALS["input"]["base"]);'
        . '$fields_data_query_edit = array();'
        . 'function get_filter_request_var($name) { return 7; }'
        . 'function isempty_request_var($name) { return false; }'
        . 'function get_request_var($name) { return 7; }'
        . 'function db_fetch_row_prepared($sql, $params = array()) { return array("id" => 7, "name" => "Interfaces", "xml_path" => $GLOBALS["input"]["xml_path"]); }'
        . 'function db_fetch_assoc_prepared($sql, $params = array()) { return array(); }'
        . 'function read_config_option($name) { return ""; }'
        . 'function __($text, ...$args) { return vsprintf($text, $args); }'
        . 'function __esc($text, ...$args) { return vsprintf($text, $args); }'
        . 'function form_start(...$args) {}'
        . 'function html_start_box(...$args) {}'
        . 'function html_end_box(...$args) {}'
        . 'function draw_edit_form(...$args) {}'
        . 'function inject_form_variables(...$args) { return array(); }'
        . 'function form_save_button(...$args) {}'
        . 'class CactiSecureHeaders { public static function getNonceAttribute() { return ""; } }'
        . 'function cacti_sizeof($array) { return is_array($array) ? count($array) : 0; }'
        . dq_path_functions('lib/functions.php', array('cacti_path_is_within', 'cacti_normalize_windows_path'))
        . dq_path_functions('data_queries.php', array('data_query_edit'))
        . 'ob_start(); data_query_edit(); $page = ob_get_clean();'
        . 'print json_encode(array("located" => str_contains($page, "Successfully located XML file"), "missing" => str_contains($page, "Could not locate XML file.")));';

    $result = dq_path_child($program, array('base' => $base, 'xml_path' => $xml_path));

    expect($result['located'] xor $result['missing'])->toBeTrue();

    return $result['located'] ? 'located' : 'missing';
}

function dq_path_import_preview(string $base, string $xml_path): array
{
    $program = '$GLOBALS["input"] = json_decode(stream_get_contents(STDIN), true);'
        . '$config = array("base_path" => $GLOBALS["input"]["base"]);'
        . '$fields_data_query_edit = array("xml_path" => array());'
        . '$fields_data_query_item_edit = array();'
        . '$preview_only = true;'
        . '$import_debug_info = array();'
        . 'function db_fetch_cell_prepared($sql, $params = array()) { return false; }'
        . 'function xml_character_decode($text) { return $text; }'
        . 'function compare_data($save, $previous, $table) { return 0; }'
        . 'function cacti_sizeof($array) { return is_array($array) ? count($array) : 0; }'
        . dq_path_functions('lib/functions.php', array('cacti_path_is_within', 'cacti_normalize_windows_path'))
        . dq_path_functions('lib/import.php', array('xml_to_data_query'))
        . '$xml = array("name" => "Fixture", "xml_path" => $GLOBALS["input"]["xml_path"], "graphs" => array());'
        . '$hash_cache = array(); $files = array();'
        . 'xml_to_data_query("hash_040024" . str_repeat("a", 32), $xml, $hash_cache, $files);'
        . 'print json_encode(array("files" => array_values($files)));';

    return dq_path_child($program, array('base' => $base, 'xml_path' => $xml_path))['files'];
}

test('the data query edit page reports only XML files the loader would read', function () {
    $base = dq_path_tree();

    try {
        foreach (dq_path_cases() as $case => [$xml_path, $readable]) {
            expect(dq_path_edit_page($base, $xml_path))->toBe($readable ? 'located' : 'missing', $case);
        }
    } finally {
        dq_path_tree_remove($base);
    }
});

test('the template import preview reports only XML files the loader would read', function () {
    $base = dq_path_tree();

    try {
        foreach (dq_path_cases() as $case => [$xml_path, $readable]) {
            expect(dq_path_import_preview($base, $xml_path))->toBe(array($readable ? 'found' : 'missing'), $case);
        }
    } finally {
        dq_path_tree_remove($base);
    }
});

test('path containment refuses a NUL byte instead of throwing', function () {
    $program = dq_path_functions('lib/functions.php', array('cacti_path_is_within', 'cacti_normalize_windows_path'))
        . 'print json_encode(array(cacti_path_is_within(__FILE__ . "\0", "/"), cacti_path_is_within("/", "/\0"), cacti_path_is_within("/usr/bin", "/usr")));';

    expect(dq_path_child($program))->toBe(array(false, false, true));
});
