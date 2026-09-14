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
 * Regression: |query_*|/|host_*| tokens in a graph title, vertical-label or
 * right-axis-label are replaced with device-supplied SNMP values. Previously
 * the substitution ran over the whole graph command AFTER each argument was
 * quoted, so a device value containing a quote could break out of the quoted
 * RRDtool argument and inject directives. The substitution must run INSIDE
 * rrdtool_quote_argument, and the post-escape global pass must be gone.
 */

require_once dirname(__DIR__, 3) . '/Helpers/RrdGraphHarness.php';

$rrd = file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php');

test('title substitution happens inside rrdtool_quote_argument', function () use ($rrd) {
	expect($rrd)->toContain(
		"'--title=' . rrdtool_quote_argument(rrd_substitute_host_query_data(html_escape(\$value), \$graph, array()))"
	);
});

test('right-axis-label substitution happens inside rrdtool_quote_argument', function () use ($rrd) {
	expect($rrd)->toMatch(
		'/--right-axis-label \' \. rrdtool_quote_argument\(rrd_substitute_host_query_data\(/'
	);
});

test('the post-escape whole-command substitution is removed', function () use ($rrd) {
	// this line injected the raw device value into already-quoted arguments
	expect($rrd)->not->toContain('$graph_opts = rrd_substitute_host_query_data($graph_opts, $graph, array());');
});

test('rrdtool_quote_argument neutralises a device-supplied quote (property this relies on)', function () {
	$result  = cacti_test_rrd_harness_run(array('action' => 'quote', 'values' => array("Traffic eth0' COMMENT:pwned")));
	$wrapped = $result['quoted'][0];

	// the inner single quote is re-opened as a double-quoted group, never left bare
	expect($wrapped)->toBe("'Traffic eth0'\"'\"' COMMENT:pwned'");
});
