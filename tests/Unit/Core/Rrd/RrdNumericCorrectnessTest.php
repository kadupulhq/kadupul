<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * rrdtool_function_create() compared the maximum against the minimum with
 * (float), and (float)'U' is 0.0, so an unbounded minimum read as a floor of
 * zero and a negative maximum was rewritten to 1. Everything above 1 then
 * stored as UNKNOWN for the life of the file.
 *
 * rrdtool_function_update() also wrote INF and NAN straight out, because both
 * satisfy is_numeric(). Measured against rrdtool 1.11, a COUNTER rejects 'NAN'
 * and fails the entire update, taking the other data sources in it with it,
 * while INF is accepted and stores infinity. Unknown is correct for both.
 *
 * Note on what is deliberately NOT changed here: (string) on a float applies
 * the `precision` ini setting and does round a large counter. Writing it at
 * full precision is worse, not better. rrdtool 1.11 accepts the rounded
 * '1.844674407371E+19' on a COUNTER and rejects var_export's exact
 * '1.8446744073709552E+19', and an integral float becomes '12345.0', which a
 * COUNTER also rejects. The fix belongs where hexdec() produces the float, in
 * lib/poller.php, not at the formatting site.
 */

namespace RrdNumericCorrectnessTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function rrd_source() : string {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php');

	expect($source)->not->toBeFalse();

	return $source;
}

it('writes unknown for a non-finite update value', function () {
	$body = \test_php_function_source(rrd_source(), 'rrdtool_function_update');

	expect($body)->toContain('!is_finite((float) $value)');

	// Both reach the branch, because is_numeric accepts them.
	expect(is_numeric(INF))->toBeTrue();
	expect(is_numeric(NAN))->toBeTrue();
	expect(is_finite(INF))->toBeFalse();
	expect(is_finite(NAN))->toBeFalse();
});

it('leaves a numeric string formatting unchanged', function () {
	$body = \test_php_function_source(rrd_source(), 'rrdtool_function_update');

	// Deliberately still (string): see the note in this file's header.
	expect($body)->toContain("str_replace(',', '.', (string)\$value)");
	expect($body)->not->toContain('var_export($value, true)');
});

if (!function_exists(__NAMESPACE__ . '\\cacti_rrd_corrected_maximum')) {
	$functions = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');

	if ($functions === false) {
		throw new \RuntimeException('Cannot read lib/functions.php for the bound helper.');
	}

	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . \test_php_function_source($functions, 'cacti_rrd_corrected_maximum'));
}

it('leaves the maximum alone when either bound is unbounded', function () {
	// (float)'U' is 0.0, which read as a floor of zero and rewrote a negative
	// maximum to 1; every later sample above 1 then stored as UNKNOWN.
	expect(cacti_rrd_corrected_maximum('U', '-1', 3))->toBe('-1');
	expect(cacti_rrd_corrected_maximum('0', 'U', 3))->toBe('U');
});

it('trims the bounds before comparing them', function () {
	// cacti_rrdtool_valid_bound() tolerates surrounding whitespace, so a stored
	// ' U ' reached the comparison and cast to 0.
	expect(cacti_rrd_corrected_maximum(' U ', '-1', 3))->toBe('-1');
	expect(cacti_rrd_corrected_maximum(' 10 ', ' 5 ', 3))->toBe('11');
});

it('still corrects a maximum that is not above a numeric minimum', function () {
	expect(cacti_rrd_corrected_maximum('10', '5', 3))->toBe('11');

	// Every branch returns a string, so callers see one type.
	expect(cacti_rrd_corrected_maximum('10', '5', 3))->toBeString();
});

it('makes GAUGE and ABSOLUTE unbounded instead of min plus one', function () {
	expect(cacti_rrd_corrected_maximum('10', '5', 1))->toBe('U');
	expect(cacti_rrd_corrected_maximum('10', '5', 4))->toBe('U');
});

it('leaves a maximum already above the minimum untouched', function () {
	expect(cacti_rrd_corrected_maximum('0', '100', 2))->toBe('100');
});

/** Run one creator's bound chain, as that file writes it, and report the max. */
function chain_result(string $file, string $min, string $max, int $type) : string {
	$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);

	expect($source)->not->toBeFalse();

	// The chain from the trim through the correction, lifted verbatim.
	$start = strpos($source, "// Trim the data source maximum");

	if ($start === false) {
		$start = strpos($source, "\$data_source['rrd_maximum'] = trim((string) \$data_source['rrd_maximum']);");
	}

	expect($start)->not->toBeFalse();

	$end = strpos($source, "/* min==max==0 won't work with rrdtool */", $start);

	expect($end)->not->toBeFalse();

	$chain = substr($source, $start, $end - $start);

	$functions = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');

	expect($functions)->not->toBeFalse();

	// The real helper, not a copy: a hand-written stub drifts from the source
	// and lets a change to it pass under a green test.
	$code = \test_php_function_source($functions, 'cacti_rrd_corrected_maximum')
		. ' function substitute_snmp_query_data(...$a) { return "0"; }'
		. ' function rrdtool_function_interface_speed(...$a) { return "0"; }'
		. ' function db_fetch_row_prepared(...$a) { return array(); }'
		. ' $speed = "0"; $local_data_id = 1; $data_local = array();'
		. ' $data_source = array('
		. '   "rrd_minimum" => ' . var_export($min, true) . ','
		. '   "rrd_maximum" => ' . var_export($max, true) . ','
		. '   "data_source_type_id" => ' . $type . ');'
		. ' ' . $chain
		. ' echo (string) $data_source["rrd_maximum"];';

	$out = array();
	exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 -r ' . escapeshellarg($code) . ' 2>/dev/null', $out);

	return implode('', $out);
}

it('makes both creators agree on every bound pair', function () {
	// A stored maximum of '0' is the case that diverged: empty('0') is true, so
	// the Boost chain called it unbounded before the shared correction ran.
	$cases = array(
		array('0', '0', 3), array('0', '0', 1),
		array('U', '-1', 3), array(' U ', '-1', 3),
		array('0', '100', 2), array('10', '5', 3), array('10', '5', 1),
		array('0', '', 3), array('0', 'U', 3),
	);

	foreach ($cases as $case) {
		list($min, $max, $type) = $case;

		$rrd   = chain_result('lib/rrd.php', $min, $max, $type);
		$boost = chain_result('lib/boost.php', $min, $max, $type);

		expect($boost)->toBe($rrd, "min=$min max=$max type=$type");
	}
});

it('goes unbounded when the minimum cannot be raised', function () {
	// Above about 1e16 a float cannot represent min+1, so the sum equals the
	// minimum. Measured against rrdtool 1.11, a DS with min == max is refused:
	// "min must be less than max in DS definition", so the file is never made.
	expect((float) '1e20' + 1 > (float) '1e20')->toBeFalse();

	expect(cacti_rrd_corrected_maximum('1e20', '1e20', 3))->toBe('U');
	expect(cacti_rrd_corrected_maximum('1e16', '5', 3))->toBe('U');

	// A minimum small enough to raise still gets the raise.
	expect(cacti_rrd_corrected_maximum('100', '50', 2))->toBe('101');
});

it('leaves a zero-zero pair unbounded for every data source type', function () {
	// The schema default for rrd_maximum is '0' and the 1.2.3 upgrade rewrote
	// only types 1, 3, 4 and 7, so COUNTER rows still carry 0/0. Answering
	// min+1 there caps the file at 1 and stores every larger rate as UNKNOWN.
	foreach (array(1, 2, 3, 4) as $type) {
		expect(cacti_rrd_corrected_maximum('0', '0', $type))->toBe('U');
		expect(chain_result('lib/rrd.php', '0', '0', $type))->toBe('U');
		expect(chain_result('lib/boost.php', '0', '0', $type))->toBe('U');
	}
});

it('writes unknown for a numeric string that parses to infinity', function () {
	$body = \test_php_function_source(rrd_source(), 'rrdtool_function_update');

	// is_numeric('1e999') is true and is_float() is false, so a float-only
	// guard let it through to rrdtool, which reads it as infinity.
	expect($body)->toContain('!is_finite((float) $value)');
	expect(is_numeric('1e999'))->toBeTrue();
	expect(is_float('1e999'))->toBeFalse();
	expect(is_finite((float) '1e999'))->toBeFalse();
});

it('treats only an empty value or the marker as unbounded', function () {
	// '0' is a real ceiling and must survive into the correction.
	expect(chain_result('lib/boost.php', '-5', '0', 3))->toBe('0');
	expect(chain_result('lib/rrd.php', '-5', '0', 3))->toBe('0');
});
