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

require_once dirname(__DIR__, 2) . '/Helpers/GraphXport.php';

use function Kadupul\Tests\GraphXport\runExport;
use function Kadupul\Tests\GraphXport\parseCsv;
use function Kadupul\Tests\GraphXport\exportMeta;

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
