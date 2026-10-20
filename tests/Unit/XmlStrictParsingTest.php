<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\XmlStrictParsing;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

$source = file_get_contents(dirname(__DIR__, 2) . '/lib/xml.php');
if ($source === false) {
    throw new \RuntimeException('Cannot read production XML parser.');
}
foreach (['xml2array', 'get_children'] as $function) {
    eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, $function));
}
$importSource = file_get_contents(dirname(__DIR__, 2) . '/lib/import.php');
if ($importSource === false) {
    throw new \RuntimeException('Cannot read production template importer.');
}
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($importSource, 'import_xml_data'));

function cacti_count(array $values): int
{
    return count($values);
}

function cacti_sizeof(mixed $value): int
{
    return is_countable($value) ? count($value) : 0;
}

test('strict XML parsing rejects malformed documents instead of returning completed children', function (string $xml) {
    expect(xml2array($xml, true))->toBe([]);
})->with([
    'trailing content' => ['<cacti><object><name>Fixture</name></object></cacti>junk'],
    'second root' => ['<cacti><object>Fixture</object></cacti><another/>'],
    'unclosed root' => ['<cacti><object>Fixture</object>'],
    'mismatched closing tag' => ['<cacti><object>Fixture</object></wrong>'],
    'empty document' => [''],
]);

test('strict and legacy XML parsing preserve valid producer data', function () {
    $xml = '<cacti><object><name>Fixture &amp; value</name></object></cacti>';
    $expected = ['object' => ['name' => 'Fixture & value']];
    expect(xml2array($xml, true))->toBe($expected)->and(xml2array($xml))->toBe($expected);
});

test('template parsing returns false so CLI web and package callers can reject malformed XML', function () {
    $keys = ['config', 'import_messages', 'ignorable_hashes', 'legacy_template', 'import_debug_info', 'hash_type_codes', 'cacti_version_codes', 'preview_only'];
    $saved = array_intersect_key($GLOBALS, array_flip($keys));
    $GLOBALS['config'] = ['library_path' => dirname(__DIR__, 2) . '/lib'];
    $GLOBALS['import_messages'] = [];
    try {
        $xml = '<cacti><object>Fixture</object></cacti>junk';
        expect(import_xml_data($xml, false, 1))->toBeFalse()
            ->and($GLOBALS['import_messages'])->toBe([7]);
    } finally {
        foreach ($keys as $key) {
            if (array_key_exists($key, $saved)) {
                $GLOBALS[$key] = $saved[$key];
            } else {
                unset($GLOBALS[$key]);
            }
        }
    }
});
