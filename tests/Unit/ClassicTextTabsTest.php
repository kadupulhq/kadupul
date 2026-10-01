<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * html_show_tabs_left() runs in a child process because lib/html.php expects
 * the application's global helpers, which the test stubs with a German
 * translation table so the assertions prove the labels come from __().
 */
function classic_tabs_render(string $currentPage, string $requestUri, ?callable $launcher = null): array
{
    $root = dirname(__DIR__, 2);
    $program = <<<'PHP'
require $argv[1] . '/lib/html.php';
function __($text, ...$args) {
    $german = array('Console' => 'Konsole', 'Graphs' => 'Graphen', 'Reporting' => 'Berichte', 'Logs' => 'Protokolle');
    return $german[$text] ?? $text;
}
function is_realm_allowed($realm) { return true; }
function get_selected_theme() { return 'classic'; }
function get_current_page($basename = true) { return $GLOBALS['argv'][2]; }
function api_plugin_hook($name) {}
function api_plugin_hook_function($name, $value) { return $value; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function db_fetch_assoc($sql) { return array(array('id' => 7, 'title' => 'Wiki <b>&'), array('id' => 9, 'title' => '監視')); }
$config = array('url_path' => '/kadupul/', 'poller_id' => 1, 'connection' => 'online');
$_SERVER['REQUEST_URI'] = $argv[3];
html_show_tabs_left();
PHP;

    $launcher ??= function_exists('proc_open') ? 'proc_open' : null;
    if ($launcher === null) {
        throw new RuntimeException('Unable to start classic tab renderer: proc_open is unavailable.');
    }
    $pipes = array();
    $process = $launcher(
        array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, $root, $currentPage, $requestUri),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start classic tab renderer process.');
    }
    $html = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return array(proc_close($process), $html, $errors);
}

test('classic tab renderer reports process startup failure before reading pipes', function () {
    expect(fn() => classic_tabs_render('index.php', '/kadupul/index.php', static fn(...$args) => false))
        ->toThrow(RuntimeException::class, 'Unable to start classic tab renderer process.');
});

test('classic tabs are translated text links, not images', function () {
    [$status, $html, $errors] = classic_tabs_render('index.php', '/kadupul/index.php');

    expect($errors)->toBe('')
        ->and($status)->toBe(0)
        ->and($html)->not->toContain('<img')
        ->and($html)->not->toContain('data:image')
        ->and($html)->toContain("<a id='tab-console' class='classicTab selected' href='/kadupul/index.php'>Konsole</a>")
        ->and($html)->toContain("<a id='tab-graphs' class='classicTab' href='/kadupul/graph_view.php'>Graphen</a>")
        ->and($html)->toContain("<a id='tab-reports' class='classicTab' href='/kadupul/reports_admin.php'>Berichte</a>")
        ->and($html)->toContain("<a id='tab-logs' class='classicTab' href='/kadupul/clog.php'>Protokolle</a>")
        ->and($html)->toContain("<a id='tab-link7' class='classicTab' href='/kadupul/link.php?id=7'>Wiki &lt;b&gt;&amp;</a>")
        ->and($html)->toContain("<a id='tab-link9' class='classicTab' href='/kadupul/link.php?id=9'>監視</a>");
});

test('classic tabs mark the current section as selected', function () {
    [, $graphs] = classic_tabs_render('graph_view.php', '/kadupul/graph_view.php?action=tree');
    [, $link] = classic_tabs_render('link.php', '/kadupul/link.php?id=9');
    [, $logs] = classic_tabs_render('clog.php', '/kadupul/clog.php');

    expect($graphs)->toContain("id='tab-graphs' class='classicTab selected'")
        ->and($graphs)->toContain("id='tab-console' class='classicTab'")
        ->and($link)->toContain("id='tab-link9' class='classicTab selected'")
        ->and($link)->toContain("id='tab-link7' class='classicTab'")
        ->and($logs)->toContain("id='tab-logs' class='classicTab selected'")
        ->and(substr_count($graphs . $link . $logs, ' selected'))->toBe(3);
});
