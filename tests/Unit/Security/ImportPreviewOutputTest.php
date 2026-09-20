<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('import preview renders untrusted fields without active HTML', function ($payload, $renderer, $rowStatus) {
    $root = dirname(__DIR__, 3);
    $directory = sys_get_temp_dir() . '/import-preview-' . bin2hex(random_bytes(8));
    mkdir($directory . '/include', 0700, true);
    file_put_contents($directory . '/include/auth.php', '<?php');
    symlink($root . '/lib', $directory . '/lib');
    symlink($root . '/include/vendor', $directory . '/include/vendor');
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
class CactiSecureHeaders { public static function getNonceAttribute() { return 'nonce="fixture"'; } }
$config = array('poller_id' => 1, 'url_path' => '/', 'base_path' => $argv[1]);
try { require $argv[1] . ($argv[3] === 'package' ? '/package_import.php' : '/templates_import.php'); } catch (RuntimeException $e) {
    if ($e->getMessage() !== 'STOP_DISPATCH') throw $e;
}
$payload = (string) simplexml_load_string('<template><name><![CDATA[' . $argv[2] . ']]></name></template>')->name;
$templates = array('files' => array($payload => 'missing'), 'hash' => array(
    'type_name' => $payload, 'name' => $payload, 'status' => $argv[4],
    'deps' => array('hash' => $payload),
    'vals' => array('differences' => array($payload, '<span style="background-color:#ff0000">ff0000</span>'), 'orphans' => array($payload))
));
if ($argv[3] === 'package') {
    $device_classes = array();
    $xmlfile = getcwd() . '/package.xml.gz';
    file_put_contents($xmlfile, gzencode('<package><publickey>dGVzdA==</publickey><info><author>Test</author><homepage>Test</homepage><email>Test</email><version>1</version><copyright>Test</copyright></info></package>'));
    unset($templates['files']);
    $templates['hash']['package'] = 'fixture';
    $templates['hash']['package_file'] = 'fixture.xml';
    $templates['hash']['vals'] = array('fixture' => $templates['hash']['vals']);
    import_display_package_data($templates, array(), 'fixture', $xmlfile, array());
} elseif ($argv[3] === 'results') {
    import_display_results(array('graph_template' => array(array('title' => $payload, 'result' => 'preview', 'type' => 'updated', 'differences' => $templates['hash']['vals']['differences']))), array(), true, true);
} else {
    display_template_data($templates);
}
PHP;
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    if ($coverage !== null) {
        $program = 'define("IMPORT_PREVIEW_TEST_COVERAGE",true);'
            . 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($directory, true) . ');'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';' . $program;
    }
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-r', $program, $root, $payload, $renderer, $rowStatus), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $directory);
        $html = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            throw new RuntimeException($errors . $html);
        }
        expect($errors)->toBe('');
        expect($html)->toContain('&lt;');
        if ($renderer === 'template' && $rowStatus === 'damaged') {
            expect($html)->toContain('Some CDEF Items will not import')->not->toContain('ff0000');
        } else {
            expect($html)->toContain('ff0000', 'background-color:');
        }
        $document = new DOMDocument();
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        if ($renderer !== 'results') {
            $expectedStatus = array('updated' => 'Updated', 'new' => 'New')[$rowStatus]
                ?? ($renderer === 'template' && $rowStatus === 'damaged' ? 'Damaged' : 'Unchanged');
            expect($xpath->query('//span[text()="' . $expectedStatus . '"]')->length)->toBeGreaterThan(0);
        }
        expect($xpath->query('//img|//svg|//iframe|//a|//style|//@*[starts-with(name(), "on")]')->length)->toBe(0);
        expect($xpath->query('//script')->length)->toBe($renderer === 'package' ? 1 : 0);
        foreach ($xpath->query('//script') as $script) {
            expect($script->textContent)->not->toContain('alert(');
        }
        foreach ($xpath->query('//*[@style]') as $element) {
            expect(strtolower($element->getAttribute('style')))->not->toContain('background-image', 'javascript:', 'url(');
        }
        if ($coverage !== null) {
            foreach (glob($directory . '/*.coverage') as $report) {
                $coverage->merge(unserialize(file_get_contents($report)));
            }
        }
    } finally {
        unlink($directory . '/lib');
        unlink($directory . '/include/vendor');
        unlink($directory . '/include/auth.php');
        rmdir($directory . '/include');
        foreach (glob($directory . '/*.coverage') as $report) {
            unlink($report);
        }
        if (file_exists($directory . '/package.xml.gz')) {
            unlink($directory . '/package.xml.gz');
        }
        rmdir($directory);
    }
})->with(array(
    '<img src=x onerror="alert(1)"><svg onload="alert(2)"></svg>',
    '<script>alert(1)</script><iframe srcdoc="unsafe"></iframe>',
    '<a href="javascript:alert(1)" onclick="alert(2)">link</a>',
    '<span style="background-image:url(javascript:alert(1))" onmouseover="alert(2)">value</span>',
))->with(array('template', 'package', 'results'))->with(array('updated', 'new', 'damaged', 'unchanged', '\" onmouseover=\"alert(9)'));
