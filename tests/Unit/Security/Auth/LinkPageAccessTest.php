<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

$root = dirname(__DIR__, 4);

$runLinkPage = function ($enabled, $allowed) use ($root) {
    $program = <<<'PHP'
namespace LinkPageRuntime;

$GLOBALS['enabled'] = $argv[1];
$GLOBALS['allowed'] = $argv[2] === '1';
$config = array('url_path' => '/', 'base_path' => getcwd());

$source = file_get_contents(getcwd() . '/link.php');
$source = str_replace(array('<?php', "include_once('./include/global.php');"), '', $source, $count);
if ($count !== 2) {
    exit(2);
}

function get_filter_request_var($name) { return '4'; }
function get_request_var($name) { return '4'; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function db_fetch_row_prepared($sql, $params = array()) {
    return array('id' => 4, 'title' => 'Status', 'style' => 'CONSOLE', 'contentfile' => 'https://example.org/', 'enabled' => $GLOBALS['enabled'], 'refresh' => 0);
}
function is_realm_allowed($realm) { return $realm === 10004 && $GLOBALS['allowed']; }
function raise_message($name, $message = '', $level = 0) { echo 'MESSAGE:' . $name . "\n"; }
function header($value) { echo 'HEADER:' . $value . "\n"; }
function html_escape($text) { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function general_header() { echo "RENDERED\n"; }
function top_header() { echo "RENDERED\n"; }
function bottom_footer() {}

eval('namespace LinkPageRuntime; ' . $source);
PHP;

    $process = proc_open(
        array(PHP_BINARY, '-r', $program, $enabled, $allowed ? '1' : '0'),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        $root
    );

    expect(is_resource($process))->toBeTrue();

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return array(proc_close($process), $stdout, $stderr);
};

test('a disabled external link page is denied even to a user holding its realm', function () use ($runLinkPage) {
    foreach (array('', 'off') as $enabled) {
        list($exit, $stdout, $stderr) = $runLinkPage($enabled, true);

        expect($exit)->toBe(0, $stderr)
            ->and($stdout)->toContain('MESSAGE:permission_denied')
            ->and($stdout)->toContain('HEADER:Location: index.php')
            ->and($stdout)->not->toContain('RENDERED')
            ->and($stdout)->not->toContain('<iframe');
    }
});

test('an enabled external link page renders only for a user holding its realm', function () use ($runLinkPage) {
    list($exit, $stdout, $stderr) = $runLinkPage('on', true);

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->toContain('RENDERED')
        ->and($stdout)->toContain('<iframe id="content" src="https://example.org/"')
        ->and($stdout)->not->toContain('permission_denied');

    list($exit, $stdout, $stderr) = $runLinkPage('on', false);

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->toContain('MESSAGE:permission_denied')
        ->and($stdout)->not->toContain('RENDERED');
});
