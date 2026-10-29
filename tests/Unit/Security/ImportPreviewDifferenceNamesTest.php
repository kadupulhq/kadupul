<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * The difference lines xml_to_host_template() builds for the import preview
 * carry object names from the uploaded XML. The preview purifier still allows
 * <em> and background-color spans for the legitimate difference markup, so a
 * name must reach it already escaped. Upstream Cacti #7238.
 */

namespace Kadupul\Tests\Security\ImportPreviewDifferenceNames;

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

function __(string $text, mixed ...$arguments): string
{
    return $arguments ? vsprintf($text, $arguments) : $text;
}

function cacti_sizeof(mixed $value): int
{
    return is_countable($value) ? count($value) : 0;
}

function db_fetch_cell_prepared(string $sql, array $params = []): mixed
{
    return false;
}

function db_fetch_row_prepared(string $sql, array $params = []): array
{
    return [];
}

function compare_data(array $save, array $previous, string $table): int
{
    return 0;
}

$importSource = file_get_contents(dirname(__DIR__, 3) . '/lib/import.php');
if (!is_string($importSource)) {
    throw new \RuntimeException('Unable to read lib/import.php.');
}
$htmlSource = file_get_contents(dirname(__DIR__, 3) . '/lib/html.php');
if (!is_string($htmlSource)) {
    throw new \RuntimeException('Unable to read lib/html.php.');
}

foreach ([[$importSource, 'xml_to_host_template'], [$importSource, 'xml_character_decode'], [$htmlSource, 'html_escape'], [$htmlSource, 'html_escape_charset']] as [$source, $function]) {
    eval('namespace ' . __NAMESPACE__ . '; ' . test_php_function_source($source, $function)); // nosemgrep: php.lang.security.eval-use.eval-use
}

test('new graph template and data query names are escaped in the preview differences', function (string $type, string $label) {
    $GLOBALS['preview_only'] = true;
    $GLOBALS['import_debug_info'] = [];
    $GLOBALS['fields_host_template_edit'] = [];
    $name = '<em>Router</em><span style="background-color:#f00">x</span>';
    $xml = ['name' => 'Host'];
    $cache = [];
    $data = [$type => ['0123456789abcdef0123456789abcdef' => $name]];

    xml_to_host_template('hash', $xml, $cache, $data);

    expect($GLOBALS['import_debug_info']['differences'])->toBe([
        $label . ': &lt;em&gt;Router&lt;/em&gt;&lt;span style=&quot;background-color:#f00&quot;&gt;x&lt;/span&gt;',
    ]);
})->with([
    'graph template' => ['graph_template', 'New Graph Template'],
    'data query' => ['data_query', 'New Data Query'],
]);
