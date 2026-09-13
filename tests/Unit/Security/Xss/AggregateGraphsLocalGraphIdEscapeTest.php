<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression: on the aggregate_graphs action-confirmation render, local_graph_id
 * is reflected into a hidden input value attribute. It must pass through
 * get_filter_request_var(), which rejects any non-integer value before output,
 * rather than the unfiltered request accessor.
 */

$source = file_get_contents(dirname(__DIR__, 4) . '/aggregate_graphs.php');

test('local_graph_id is integer-filtered where it is reflected into the hidden input', function () use ($source) {
	expect($source)->not->toBeFalse();

	$matched = preg_match_all("/<input type='hidden' name='local_graph_id' value='[^\\n]*/", $source, $inputs);
	expect($matched)->toBe(1);

	$input = $inputs[0][0];
	expect($input)->toContain("(isset_request_var('local_graph_id') ? get_filter_request_var('local_graph_id') : 0)");
	// the raw reflection must be gone
	expect($input)->not->toContain('get_nfilter_request_var');
	expect($input)->not->toContain("get_request_var('local_graph_id')");
});
