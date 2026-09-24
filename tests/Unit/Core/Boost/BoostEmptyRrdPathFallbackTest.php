<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * boost_process_poller_output() chose between the template lookup and a
 * poller_item fallback with cacti_sizeof($path_template). That lookup always
 * returns array('rrd_path' => ..., 'rrd_template' => ...), so the count is
 * always 2 and the fallback was unreachable.
 *
 * It matters because the lookup joins graph_templates_item: a data source that
 * is polled but carries no graph item comes back with an empty rrd_path, and
 * boost_rrdtool_function_update() returns 'OK' for an empty path. 'OK' means
 * the staged rows are forwarded and deleted, so the samples were discarded and
 * counted as updates.
 *
 * The branch is read from the file and executed against a recorder, so the
 * assertion is which query runs rather than the shape of the source.
 */

namespace BoostEmptyRrdPathFallbackTest;

function boost_source() : string {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/boost.php');

	expect($source)->not->toBeFalse();

	return $source;
}

/** The selection between the template lookup and the poller_item fallback. */
function selection_block(string $source) : string {
	$start = strpos($source, '$path_template = boost_get_rrd_filename_and_template($local_data_id);');

	expect($start)->not->toBeFalse();

	$end = strpos($source, "cacti_log('The RRDpath is '", $start);

	expect($end)->not->toBeFalse();

	return substr($source, $start, $end - $start);
}

/**
 * Run the branch with the template lookup returning $path, and report whether
 * the poller_item fallback was consulted and what path came out.
 */
function resolve(string $block, string $path) : array {
	$code = 'namespace ' . __NAMESPACE__ . ';'
		. ' $GLOBALS["berp_fallback"] = false;'
		. ' $local_data_id = 7;'
		. ' $rrd_path = ""; $rrd_tmpl = "";'
		. ' function boost_get_rrd_filename_and_template($id) {'
		. '   return array("rrd_path" => ' . var_export($path, true) . ', "rrd_template" => "a:b");'
		. ' }'
		. ' function db_fetch_cell_prepared($sql, $params = array()) {'
		. '   $GLOBALS["berp_fallback"] = true;'
		. '   return "/var/lib/cacti/rra/from_poller_item.rrd";'
		. ' }'
		. ' function cacti_sizeof($a) { return is_array($a) ? count($a) : 0; }'
		. ' ' . $block
		. ' echo json_encode(array("path" => $rrd_path, "fallback" => $GLOBALS["berp_fallback"]));';

	$out = array();
	exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null', $out);

	$decoded = json_decode(implode('', $out), true);

	expect($decoded)->toBeArray();

	return $decoded;
}

it('falls back to poller_item when the template lookup has no path', function () {
	$result = resolve(selection_block(boost_source()), '');

	expect($result['fallback'])->toBeTrue();
	expect($result['path'])->toBe('/var/lib/cacti/rra/from_poller_item.rrd');
});

it('keeps the template path when the lookup found one', function () {
	$result = resolve(selection_block(boost_source()), '/var/lib/cacti/rra/from_template.rrd');

	expect($result['fallback'])->toBeFalse();
	expect($result['path'])->toBe('/var/lib/cacti/rra/from_template.rrd');
});
