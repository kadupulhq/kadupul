<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\StringPredicates;

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

// Load the production functions without starting the database or poller.
foreach (['lib/poller.php' => 'file_escaped', 'lib/database.php' => 'db_format_index_create', 'lib/html_utility.php' => 'form_alternate_row_class'] as $file => $function) {
    eval('namespace ' . __NAMESPACE__ . '; ' . \test_php_function_source(file_get_contents(dirname(__DIR__, 3) . '/' . $file), $function));
}

test('file quote detection requires both literal ends', function ($file, $expected) {
    expect(file_escaped($file))->toBe($expected);
})->with([
    ['', false], ['plain', false], ['"plain"', true], ['"plain', false],
    ['plain"', false], ['a"b', false], ['"', true], ['""', true],
    ["\"a\0b\"", true], ["\"a\"\n", false],
]);

test('index formatting distinguishes expression suffixes from ordinary names', function ($indexes, $expected) {
    expect(db_format_index_create($indexes))->toBe($expected);
})->with([
    ['name', '`name`'], ['', '``'], [' name ', '`name`'],
    ['name(10)', 'name(10)'], [' name(10) ', 'name(10)'],
    ['name(10)x', '`name(10)x`'], ['name(10', '`name(10`'],
    [[], ''], [['name', 'value(10)'], '`name`,value(10)'],
    [['name', 'value(10)x'], '`name`,`value(10)x`'],
]);

test('row prefix checks preserve numeric ids and disabled rows', function ($id, $disabled, $expected) {
    ob_start();
    try {
        form_alternate_row_class($id, 'probe', $disabled);
        $actual = ob_get_contents();
    } finally {
        ob_end_clean();
    }
    expect($actual)->toBe($expected);
})->with([
    ['', false, "<tr class='probe'>"],
    [0, false, "<tr class='probe selectable' id='0'>"],
    [12, false, "<tr class='probe selectable' id='12'>"],
    ['row_12', false, "<tr class='probe' id='row_12'>"],
    ['row', false, "<tr class='probe selectable' id='row'>"],
    ['xrow_12', false, "<tr class='probe selectable' id='xrow_12'>"],
    ['ROW_12', false, "<tr class='probe selectable' id='ROW_12'>"],
    ['12', true, "<tr class='probe' id='12'>"],
]);
