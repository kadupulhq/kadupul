<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * rrd_create_definition() is the one builder the two RRD creators now share.
 * They each had their own copy and the copies drifted. Two of the differences
 * had already cost data, which is why the bound correction was merged first;
 * these are the rest:
 *
 * - Boost joined data_source_profiles_rra and _cf on dtd.data_source_profile_id
 *   rather than on dsp.id, so it still found RRA rows when the profile row had
 *   been deleted. x_files_factor is NULL there and the RRA line it wrote was
 *   malformed, where lib/rrd.php found no RRA and refused.
 * - Boost left the RRA order undetermined when two profiles tie on rows times
 *   steps, so the same data source could get its RRAs in either order.
 * - Boost said nothing when a data source had no RRA at all.
 * - lib/rrd.php read the device row and its interface speed on every create,
 *   two queries each time, when only a |query_ maximum needs them.
 *
 * This runs the shipped function with the database stubbed, so a regression in
 * any of that shows up as behaviour.
 */

namespace RrdCreateDefinitionTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

// test-only eval of source read from this repository, not external input
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(
	file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php'),
	'rrd_create_definition'
));

const RRD_NL = " \\\n";

/*
 * The function includes global_arrays.php for the two lookup tables. The real
 * one pulls in translation and a great deal else, so stand up a directory with
 * just those tables, for the life of the process.
 */
$GLOBALS['definition_include'] = sys_get_temp_dir() . '/rrd-definition-' . getmypid() . '-' . mt_rand();

mkdir($GLOBALS['definition_include'], 0700, true);

file_put_contents($GLOBALS['definition_include'] . '/global_arrays.php', '<?php'
	. ' $data_source_types = array(1 => "GAUGE", 2 => "COUNTER", 3 => "COUNTER", 4 => "ABSOLUTE");'
	. ' $consolidation_functions = array(1 => "AVERAGE", 3 => "MAX");');

register_shutdown_function(static function () {
	@unlink($GLOBALS['definition_include'] . '/global_arrays.php');
	@rmdir($GLOBALS['definition_include']);
});

function cacti_log($message, ...$args) {
	$GLOBALS['definition_logs'][] = $message;
}

function cacti_sizeof($value) {
	return is_array($value) ? count($value) : 0;
}

function cacti_rrdtool_valid_ds_name($name) {
	return $name !== '' && preg_match('/^[a-zA-Z0-9_]{1,19}\z/', $name) === 1;
}

function cacti_rrdtool_valid_bound($value) {
	return $value === 'U' || is_numeric($value);
}

function cacti_rrd_corrected_maximum($minimum, $maximum, $type) {
	$GLOBALS['definition_corrections'][] = array($minimum, $maximum, $type);

	return $maximum;
}

function get_data_source_item_name($id) {
	return $GLOBALS['definition_names'][$id] ?? ('ds' . $id);
}

function substitute_snmp_query_data($value, $host, $query, $index) {
	return '4242';
}

function rrdtool_function_interface_speed($data_local) {
	$GLOBALS['definition_queries'][] = 'interface_speed';

	return '1000000000';
}

function db_fetch_assoc_prepared($sql, $params = array(), ...$args) {
	$flat = preg_replace('/\s+/', ' ', trim($sql));

	$GLOBALS['definition_queries'][] = $flat;

	if (strpos($flat, 'data_source_profiles_rra') !== false) {
		return $GLOBALS['definition_rras'];
	}

	return $GLOBALS['definition_sources'];
}

function db_fetch_cell_prepared($sql, $params = array(), ...$args) {
	$GLOBALS['definition_queries'][] = 'data_template_id';

	return $GLOBALS['definition_template_id'];
}

function db_fetch_row_prepared($sql, $params = array(), ...$args) {
	$GLOBALS['definition_queries'][] = 'data_local';

	return array('host_id' => 1, 'snmp_query_id' => 2, 'snmp_index' => '3');
}

/** Build the definition for one set of rows and report what it did. */
function build(array $rras, array $sources, $template_id = 1) : array {
	$GLOBALS['config']                  = array('include_path' => $GLOBALS['definition_include']);
	$GLOBALS['definition_rras']         = $rras;
	$GLOBALS['definition_sources']      = $sources;
	$GLOBALS['definition_template_id']  = $template_id;
	$GLOBALS['definition_logs']         = array();
	$GLOBALS['definition_queries']      = array();
	$GLOBALS['definition_corrections']  = array();

	$definition = rrd_create_definition(7, 'POLLER');

	return array(
		'definition'  => $definition,
		'logs'        => $GLOBALS['definition_logs'],
		'queries'     => $GLOBALS['definition_queries'],
		'corrections' => $GLOBALS['definition_corrections'],
	);
}

function one_rra(array $overrides = array()) : array {
	return array_merge(array(
		'rrd_step'                 => 300,
		'x_files_factor'           => '0.5',
		'steps'                    => 1,
		'rows'                     => 600,
		'consolidation_function_id' => 1,
		'rra_order'                => 600,
	), $overrides);
}

function one_source(array $overrides = array()) : array {
	return array_merge(array(
		'id'                  => 4,
		'data_source_name'    => 'value',
		'rrd_heartbeat'       => 600,
		'rrd_minimum'         => '0',
		'rrd_maximum'         => '100',
		'data_source_type_id' => 3,
	), $overrides);
}

it('builds the step, the data sources and the RRAs in that order', function () {
	$run = build(
		array(one_rra(), one_rra(array('consolidation_function_id' => 3, 'steps' => 6, 'rows' => 700))),
		array(one_source(), one_source(array('id' => 5)))
	);

	$definition = $run['definition'];

	expect($definition)->toContain('--start 0 --step 300');
	expect($definition)->toContain('DS:ds4:COUNTER:600:0:100');
	expect($definition)->toContain('DS:ds5:COUNTER:600:0:100');
	expect($definition)->toContain('RRA:AVERAGE:0.5:1:600');
	expect($definition)->toContain('RRA:MAX:0.5:6:700');

	// Every DS line precedes every RRA line, which is what rrdtool requires.
	expect(strpos($definition, 'DS:ds5'))->toBeLessThan(strpos($definition, 'RRA:'));
});

it('refuses and says so when the data source has no RRA', function () {
	$run = build(array(), array(one_source()));

	// Boost returned false here without a word, so a data source that stopped
	// being written gave no reason anywhere.
	expect($run['definition'])->toBeFalse();
	expect(implode(' | ', $run['logs']))->toContain("There are no RRA's assigned");
});

it('joins the profile rows through the profile itself', function () {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php');

	expect($source)->not->toBeFalse();

	$body = \test_php_function_source($source, 'rrd_create_definition');

	// On dsp.id, not on dtd.data_source_profile_id. The profile join is a LEFT
	// one, so joining around it finds RRA rows for a profile that has been
	// deleted, and x_files_factor is then NULL in the RRA line.
	expect($body)->toContain('ON dsp.id=dspr.data_source_profile_id');
	expect($body)->toContain('ON dsp.id=dspc.data_source_profile_id');
	expect($body)->not->toContain('ON dtd.data_source_profile_id=dspr');
	expect($body)->not->toContain('ON dtd.data_source_profile_id=dspc');

	// And the order is settled when rows times steps ties.
	expect($body)->toContain('ORDER BY dspc.consolidation_function_id, rra_order, dspr.steps');
});

it('reads the device only for a maximum that needs it', function () {
	$plain = build(array(one_rra()), array(one_source(), one_source(array('id' => 5))));

	// Two queries per create for a device almost no data source asks about.
	expect($plain['queries'])->not->toContain('data_local');
	expect($plain['queries'])->not->toContain('interface_speed');

	$queried = build(array(one_rra()), array(
		one_source(array('rrd_maximum' => '|query_ifSpeed|')),
		one_source(array('id' => 5, 'rrd_maximum' => '|query_ifHighSpeed|')),
	));

	expect($queried['definition'])->toContain('DS:ds4:COUNTER:600:0:1000000000');
	expect($queried['definition'])->toContain('DS:ds5:COUNTER:600:0:1000000000');

	// Read once for the pair, not once per data source.
	expect(count(array_keys($queried['queries'], 'data_local', true)))->toBe(1);
	expect(count(array_keys($queried['queries'], 'interface_speed', true)))->toBe(1);
});

it('substitutes a query maximum that is not a speed', function () {
	$run = build(array(one_rra()), array(one_source(array('rrd_maximum' => '|query_ifMtu|'))));

	expect($run['definition'])->toContain('DS:ds4:COUNTER:600:0:4242');
});

it('sends a plain maximum through the shared correction', function () {
	$run = build(array(one_rra()), array(one_source(array('rrd_minimum' => '10', 'rrd_maximum' => '5'))));

	expect($run['corrections'])->toBe(array(array('10', '5', 3)));
});

it('leaves an unbounded maximum alone without correcting it', function () {
	foreach (array('', 'U') as $maximum) {
		$run = build(array(one_rra()), array(one_source(array('rrd_maximum' => $maximum))));

		expect($run['corrections'])->toBe(array());
		expect($run['definition'])->toContain('DS:ds4:COUNTER:600:0:U');
	}
});

it('refuses a data source name the command stream would not take', function () {
	$GLOBALS['definition_names'] = array(4 => 'bad name');

	try {
		$run = build(array(one_rra()), array(one_source()));

		expect($run['definition'])->toBeFalse();
		expect(implode(' | ', $run['logs']))->toContain('Invalid RRD data source name');
	} finally {
		$GLOBALS['definition_names'] = array();
	}
});

it('refuses bounds the command stream would not take', function () {
	$run = build(array(one_rra()), array(one_source(array('rrd_minimum' => "0\n--x"))));

	expect($run['definition'])->toBeFalse();
	expect(implode(' | ', $run['logs']))->toContain('Invalid RRD data source bounds');
});

it('keeps a zero minimum and maximum unbounded', function () {
	// The schema default leaves COUNTER rows at 0/0, and min == max is refused
	// by rrdtool, so the file would never be created.
	$run = build(array(one_rra()), array(one_source(array('rrd_minimum' => '0', 'rrd_maximum' => '0'))));

	expect($run['definition'])->toContain('DS:ds4:COUNTER:600:0:U');
});

it('names the calling facility in its log lines', function () {
	$GLOBALS['config']                 = array('include_path' => $GLOBALS['definition_include']);
	$GLOBALS['definition_rras']        = array();
	$GLOBALS['definition_sources']     = array();
	$GLOBALS['definition_template_id'] = 1;
	$GLOBALS['definition_logs']        = array();

	// Both creators log under their own facility, which is the one thing the
	// shared builder cannot decide for itself.
	$body = \test_php_function_source(
		file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php'),
		'rrd_create_definition'
	);

	// Every log line the builder writes carries the caller's facility, and the
	// parameter has no default, so a new caller has to say which it is.
	expect(preg_match_all('/cacti_log\\(/', $body))->toBeGreaterThan(0);
	expect(preg_match_all('/cacti_log\\([^;]*\\$facility\\)/', $body))->toBe(preg_match_all('/cacti_log\\(/', $body));
	expect($body)->toContain('function rrd_create_definition($local_data_id, $facility)');

	foreach (array('lib/rrd.php' => 'POLLER', 'lib/boost.php' => 'BOOST') as $file => $facility) {
		$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);

		expect($source)->not->toBeFalse();
		expect($source)->toContain("rrd_create_definition(\$local_data_id, '$facility')");
	}
});
