<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('import preview renders untrusted fields without active HTML', function ($payload) {
    $root = dirname(__DIR__, 3);
    $directory = sys_get_temp_dir() . '/import-preview-' . bin2hex(random_bytes(8));
    mkdir($directory . '/include', 0700, true);
    file_put_contents($directory . '/include/auth.php', '<?php');
    symlink($root . '/lib', $directory . '/lib');
    $program = <<<'PHP'
require $argv[1] . '/lib/html.php';
require $argv[1] . '/lib/html_utility.php';
require $argv[1] . '/include/vendor/ezyang/htmlpurifier/library/HTMLPurifier.auto.php';
function top_header() { throw new RuntimeException('STOP_DISPATCH'); }
function read_config_option($name) { return '0'; }
function is_resource_writable($path) { return true; }
function api_plugin_hook_function($name, $value) { return $value; }
function __($text, ...$args) { return $args ? vsprintf($text, $args) : $text; }
function __esc($text, ...$args) { return html_escape(__($text, ...$args)); }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_count($value) { return cacti_sizeof($value); }
function get_current_page() { return 'preview-test.php'; }
$config = array('poller_id' => 1, 'url_path' => '/', 'base_path' => $argv[1]);
try { require $argv[1] . '/templates_import.php'; } catch (RuntimeException $e) {
    if ($e->getMessage() !== 'STOP_DISPATCH') throw $e;
}
$payload = (string) simplexml_load_string('<template><name><![CDATA[' . $argv[2] . ']]></name></template>')->name;
$templates = array('files' => array($payload => 'missing'), 'hash' => array(
    'type_name' => $payload, 'name' => $payload, 'status' => 'updated',
    'deps' => array('hash' => $payload),
    'vals' => array('differences' => array($payload, '<span style="background-color:#ff0000">ff0000</span>'), 'orphans' => array($payload))
));
display_template_data($templates);
PHP;
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    if ($coverage !== null) {
        $program = 'define("IMPORT_PREVIEW_TEST_COVERAGE",true);'
            . 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($directory, true) . ');'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';' . $program;
    }
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $program, $root, $payload), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $directory);
        $html = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            throw new RuntimeException($errors . $html);
        }
        expect($errors)->toBe('');
        expect($html)->toContain('&lt;', 'ff0000', 'background-color:');
        $document = new DOMDocument();
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        expect($xpath->query('//img|//svg|//script|//iframe|//a|//style|//@*[starts-with(name(), "on")]')->length)->toBe(0);
        if ($coverage !== null) {
            foreach (glob($directory . '/*.coverage') as $report) {
                $coverage->merge(unserialize(file_get_contents($report)));
            }
        }
    } finally {
        unlink($directory . '/lib');
        unlink($directory . '/include/auth.php');
        rmdir($directory . '/include');
        foreach (glob($directory . '/*.coverage') as $report) {
            unlink($report);
        }
        rmdir($directory);
    }
})->with(array(
    '<img src=x onerror="alert(1)"><svg onload="alert(2)"></svg>',
    '<script>alert(1)</script><iframe srcdoc="unsafe"></iframe>',
    '<a href="javascript:alert(1)" onclick="alert(2)">link</a>',
    '<span style="background-image:url(javascript:alert(1))" onmouseover="alert(2)">value</span>',
));
