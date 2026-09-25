<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * rrdtool_function_graph() concatenated graph_templates_item.value straight
 * into the command written to `rrdtool -`: four SHIFT sites and the TIC
 * fraction. That stream is line oriented (RRD_NL is " \\\n"), rrdtool has no
 * escape character, and `create`, `restore` and `graph <outfile>` are all
 * accepted on it, so a newline in the value started a new rrdtool command.
 *
 * Three of the four SHIFT guards used `$graph_item['value'] > 0`. Under PHP 8
 * a non-numeric string compares against the int as a string, so '300 x' > 0 is
 * true and the value was emitted. The fourth used abs(), which throws a
 * TypeError on PHP 8 instead, a render abort rather than an injection, but
 * still driven by the same unvalidated column. TIC had no numeric test at all.
 *
 * VRULE, a few lines below, did gate on is_numeric(), which is why it looked
 * safe. It is not sufficient on its own: is_numeric() accepts leading and
 * trailing whitespace, so "300\n" passes it and the newline still ends the
 * command. All three now use cacti_rrdtool_valid_offset(), which additionally
 * requires the value to equal its own trim, so a numeric value behaves exactly
 * as before and anything else is dropped rather than concatenated.
 *
 * dashes and dash_offset are the same shape of problem in the same command and
 * are covered here too: both editors anchor their save patterns with \z, and
 * the emit gates on the shared validators, because rows stored before those
 * patterns existed are unconstrained and an imported template never passes
 * through either editor.
 */

namespace RrdShiftTicValueNumericTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

if (!function_exists(__NAMESPACE__ . '\\cacti_rrdtool_valid_offset')) {
	$functions = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');

	if ($functions === false) {
		throw new \RuntimeException('Cannot read lib/functions.php for the offset validator.');
	}

	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . \test_php_function_source($functions, 'cacti_rrdtool_valid_offset'));
}

function rrd_source() : string {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php');

	expect($source)->not->toBeFalse();

	return $source;
}

/** Every line that concatenates the raw value into the command stream. */
function emit_lines(string $source) : array {
	$lines = array();

	foreach (explode("\n", $source) as $number => $line) {
		if (strpos($line, "'SHIFT:' . \$data_source_name . ':' . \$graph_item['value']") !== false) {
			$lines[$number + 1] = $line;
		}
	}

	return $lines;
}

it('still has the four SHIFT emit sites', function () {
	expect(emit_lines(rrd_source()))->toHaveCount(4);
});

it('guards every SHIFT emit site with the offset validator', function () {
	$source = rrd_source();
	$all    = explode("\n", $source);

	foreach (emit_lines($source) as $number => $line) {
		// Walk up to the nearest `if`; one site carries its comment on a line
		// of its own, so a fixed offset misses the guard.
		$guard = null;

		for ($i = $number - 2; $i >= 0 && $i > $number - 6; $i--) {
			if (strpos($all[$i], 'if (') !== false) {
				$guard = $all[$i];

				break;
			}
		}

		expect($guard)->not->toBeNull();
		expect($guard)->toContain('cacti_rrdtool_valid_offset($graph_item[\'value\'])');
	}
});

it('skips a TIC item whose fraction is unusable', function () {
	$body = rrd_source();

	// Measured against rrdtool 1.11: TICK:v#rrggbb:legend and
	// TICK:v#rrggbb::legend are both rejected with "error parsing number", and
	// a rejected item fails the whole graph, so emitting the item without a
	// usable fraction is worse than not emitting it.
	expect($body)->toContain("if (!empty(\$graph_item['graph_type_id']) && cacti_rrdtool_valid_offset(\$graph_item['value'])) {");

	// And no fraction placeholder is emitted on its own.
	expect($body)->not->toContain('$_fraction');
});

it('the offset validator rejects every shape that reaches the stream', function () {
	// Text after the digits, which the old `> 0` comparison accepted.
	expect(cacti_rrdtool_valid_offset('300 COMMENT:x'))->toBeFalse();
	expect(cacti_rrdtool_valid_offset("300 \\\nCOMMENT:x"))->toBeFalse();
	expect(cacti_rrdtool_valid_offset("300\ngraph /tmp/x"))->toBeFalse();

	// Whitespace only, which is_numeric() on its own accepts. A newline ends
	// the command and a space starts another argument, so both must fail.
	expect(cacti_rrdtool_valid_offset("300\n"))->toBeFalse();
	expect(cacti_rrdtool_valid_offset("\n300"))->toBeFalse();
	expect(cacti_rrdtool_valid_offset(' 300'))->toBeFalse();
	expect(cacti_rrdtool_valid_offset('300 '))->toBeFalse();

	// Legitimate offsets are untouched.
	expect(cacti_rrdtool_valid_offset('300'))->toBeTrue();
	expect(cacti_rrdtool_valid_offset('-300'))->toBeTrue();
	expect(cacti_rrdtool_valid_offset('0.5'))->toBeTrue();
	expect(cacti_rrdtool_valid_offset(300))->toBeTrue();
});

it('is_numeric alone would have let the whitespace shapes through', function () {
	expect(is_numeric("300\n"))->toBeTrue();
	expect(is_numeric(' 300'))->toBeTrue();
});

it('shows the old comparison would have emitted a non-numeric value', function () {
	// PHP 8 compares a non-numeric string against an int as a string, so the
	// previous `> 0` guard was satisfied by a payload beginning with a digit.
	expect('300 COMMENT:x' > 0)->toBeTrue();
});

it('gates the dashes and dash-offset emits on a strict shape', function () {
	$body = rrd_source();

	expect($body)->toContain('cacti_rrdtool_valid_dash_list($graph_item[\'dashes\'])');
	expect($body)->toContain('cacti_rrdtool_valid_offset($graph_item[\'dash_offset\'])');
});

it('rejects a dash list carrying whitespace', function () {
	require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

	$functions = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');

	expect($functions)->not->toBeFalse();

	if (!function_exists(__NAMESPACE__ . '\\cacti_rrdtool_valid_dash_list')) {
		// test-only eval of source read from this repository, not external input
		eval('namespace ' . __NAMESPACE__ . '; ' . \test_php_function_source($functions, 'cacti_rrdtool_valid_dash_list'));
	}

	expect(cacti_rrdtool_valid_dash_list('5'))->toBeTrue();
	expect(cacti_rrdtool_valid_dash_list('5,10'))->toBeTrue();

	// Whitespace is what matters here: the emit writes the value bare into a
	// stream that splits arguments on spaces and ends the command on a newline.
	expect(cacti_rrdtool_valid_dash_list("5\n"))->toBeFalse();
	expect(cacti_rrdtool_valid_dash_list('5 '))->toBeFalse();
	expect(cacti_rrdtool_valid_dash_list('5,'))->toBeFalse();
	expect(cacti_rrdtool_valid_dash_list('a'))->toBeFalse();
});

it('anchors the save patterns at the true end of the value', function () {
	foreach (array('graphs_items.php', 'graph_templates_items.php') as $file) {
		$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);

		expect($source)->not->toBeFalse();

		// Each field is asserted on its own: checking only that the file holds
		// some \z would pass while one of the two regressed to $.
		foreach (array('dashes', 'dash_offset') as $field) {
			preg_match("/'" . $field . "',\s*'([^']*)'/", $source, $match);

			expect($match)->not->toBeEmpty();
			expect($match[1])->toEndWith('\z');
			expect($match[1])->not->toContain('$');
		}
	}

	// Demonstrating why: $ is not an end-of-string anchor in PCRE by default.
	expect(preg_match('/^[0-9]+[,0-9]*$/', "5\n"))->toBe(1);
	expect(preg_match('/^[0-9]+[,0-9]*\z/', "5\n"))->toBe(0);
});

it('accepts the same dash lists at save and at emit', function () {
	require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

	$functions = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');
	$editor    = file_get_contents(dirname(__DIR__, 4) . '/graphs_items.php');

	expect($functions)->not->toBeFalse();
	expect($editor)->not->toBeFalse();

	if (!function_exists(__NAMESPACE__ . '\\cacti_rrdtool_valid_dash_list')) {
		// test-only eval of source read from this repository, not external input
		eval('namespace ' . __NAMESPACE__ . '; ' . \test_php_function_source($functions, 'cacti_rrdtool_valid_dash_list'));
	}

	preg_match("/'dashes',\s*'([^']*)'/", $editor, $match);

	expect($match)->not->toBeEmpty();

	// A value the editor stores but the emit refuses would validate on save and
	// then have its dashes vanish silently at render time.
	foreach (array('5', '5,10', '5,', '5,,', '5 ', "5\n", 'a') as $value) {
		expect(preg_match('/' . $match[1] . '/', $value) === 1)
			->toBe(cacti_rrdtool_valid_dash_list($value), 'disagreement on ' . var_export($value, true));
	}
});

it('guards the VRULE value with the offset validator too', function () {
	$body = rrd_source();

	// VRULE appends its value bare in the same way SHIFT and TIC do.
	expect($body)->toContain("} elseif (cacti_rrdtool_valid_offset(\$graph_item['value'])) {");
});

it('validates dashes identically in both graph item editors', function () {
	$per_graph = file_get_contents(dirname(__DIR__, 4) . '/graphs_items.php');
	$template  = file_get_contents(dirname(__DIR__, 4) . '/graph_templates_items.php');

	expect($per_graph)->not->toBeFalse();
	expect($template)->not->toBeFalse();

	$pattern = "/'dashes',\s*'([^']*)'/";

	preg_match($pattern, $per_graph, $a);
	preg_match($pattern, $template, $b);

	expect($a)->not->toBeEmpty();
	expect($b)->not->toBeEmpty();
	expect($a[1])->toBe($b[1]);
	expect($a[1])->not->toBe('');
});
