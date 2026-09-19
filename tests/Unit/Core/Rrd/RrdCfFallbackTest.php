<?php
// SPDX-FileCopyrightText: 2004-2026 The Cacti Group
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later
require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function get_rrd_cfs($local_data_id) { return $GLOBALS['cf_available']; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
eval(test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php'), 'generate_graph_best_cf'));

/** Run the shipped CDEF selection statements with the DEF cache from the first pass. */
function graph_cdef_selected_cf(array $available, $requested, $reference) {
    $source = file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php');
    $marker = strpos($source, 'GRAPH ITEMS: CDEF +');
    if ($marker === false || !preg_match('/\n[\t ]*\/\* \++ GRAPH ITEMS: CDEF START/', $source, $end, PREG_OFFSET_CAPTURE, $marker)) {
        throw new RuntimeException('CDEF selection section not found');
    }
    $start = strpos($source, '$dtr_id =', $marker);
    if ($start === false || $start >= $end[0][1]) { throw new RuntimeException('CDEF selection start not found'); }
    $body = substr($source, $start, $end[0][1] - $start);
    $select = eval('return static function ($graph_item, $cf_ds_cache, $rra_seconds) {' . $body . 'return $cf_id; };');
    $GLOBALS['cf_available'] = $available;
    return $select(array('local_data_id' => 10, 'data_template_rrd_id' => 20,
        'consolidation_function_id' => $requested, 'cf_reference' => $reference),
        array(20 => array_fill_keys($available, 0)), 60);
}

test('CDEF selection preserves the valid reference selected while building DEFs', function ($available, $requested, $reference) {
    expect(graph_cdef_selected_cf($available, $requested, $reference))->toBe($reference);
})->with(array(
    array(array(1), 4, 1), // LAST is missing: selecting the requested CF would reference an absent DEF.
    array(array(3), 1, 3),
    array(array(2), 4, 2),
    array(array(4), 1, 4),
    array(array(1, 3), 3, 1), // A GPRINT may inherit the visible item's CF.
    array(array(1, 2, 3, 4), 3, 3),
));

test('graph placeholders without a data-source reference keep the AVERAGE default', function () {
    expect(graph_cdef_selected_cf(array(), 1, null))->toBe(1);
});


test('DEF selection chooses an available consolidation function before CDEF references it', function ($available, $requested, $expected) {
    $GLOBALS['cf_available'] = $available;
    $reference = generate_graph_best_cf(10, $requested, 60);
    expect($reference)->toBe($expected)
        ->and(graph_cdef_selected_cf($available, $requested, $reference))->toBe($expected);
})->with(array(
    array(array(1), 4, 1), array(array(3, 2), 4, 3), array(array(2, 3), 4, 2),
    array(array(4), 1, 4), array(array(1, 3), 3, 3), array(array(), 4, 1),
));
