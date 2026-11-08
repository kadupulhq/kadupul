<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression coverage for Cacti issue #8426 (#1034, #1095): the windowed
 * standard-deviation mean comes from the samples around the window, and
 * unknown samples and limits are ignored when building and applying the
 * cutoffs.
 */

require_once dirname(__DIR__, 4) . '/lib/spikekill.php';

if (!function_exists('cacti_sizeof')) {
	function cacti_sizeof($value) {
		return is_array($value) ? count($value) : 0;
	}
}

test('windowed deviation uses the surrounding sample mean', function ($minimum, $maximum) {
	$repair = (new ReflectionClass(spikekill::class))->newInstanceWithoutConstructor();
	$repair->method = SPIKE_METHOD_STDDEV;
	$repair->out_start = 150;
	$repair->out_end = 250;
	$repair->stddev = 2;
	$repair->percent = 0;
	foreach (['ds_min' => [$minimum], 'ds_max' => [$maximum]] as $name => $value) {
		(new ReflectionProperty(spikekill::class, $name))->setValue($repair, $value);
	}
	$statistics = [[['sumofsamples' => 1020, 'numsamples' => 3]]];
	$samples = [[[100 => '10', 200 => '1000', 225 => 'NaN', 300 => '10', 400 => 'NaN']]];
	(new ReflectionMethod(spikekill::class, 'calculateOverallStatistics'))->invokeArgs($repair, [&$statistics, &$samples]);
	expect($statistics[0][0]['average'])->toEqual(10)
		->and($statistics[0][0]['stddev'])->toEqual(0)
		->and($statistics[0][0]['min_cutoff'])->toEqual(10)
		->and($statistics[0][0]['max_cutoff'])->toEqual(10)
		->and($statistics[0][0]['stddev_killed'])->toBe(1);
})->with([[0, 10000], ['NaN', 'NaN']]);

test('nonwindowed or empty-reference deviation retains its existing mean', function ($start, $end) {
	$repair = (new ReflectionClass(spikekill::class))->newInstanceWithoutConstructor();
	$repair->method = SPIKE_METHOD_STDDEV;
	$repair->out_start = $start;
	$repair->out_end = $end;
	$repair->stddev = 2;
	$repair->percent = 0;
	foreach (['ds_min' => ['NaN'], 'ds_max' => ['NaN']] as $name => $value) {
		(new ReflectionProperty(spikekill::class, $name))->setValue($repair, $value);
	}
	$statistics = [[['sumofsamples' => 1020, 'numsamples' => 3]]];
	$samples = [[[100 => '10', 200 => '1000', 300 => '10']]];
	(new ReflectionMethod(spikekill::class, 'calculateOverallStatistics'))->invokeArgs($repair, [&$statistics, &$samples]);
	expect($statistics[0][0]['average'])->toEqual(340);
})->with([[0, 0], [1, 400]]);

test('an unknown sample inside the window is not replaced as a deviation', function () {
	$repair = (new ReflectionClass(spikekill::class))->newInstanceWithoutConstructor();
	$repair->method = SPIKE_METHOD_STDDEV;
	$repair->avgnan = 'avg';
	$repair->numspike = 10;
	$repair->out_start = 150;
	$repair->out_end = 250;
	$statistics = [[['average' => 10, 'min_cutoff' => 10, 'max_cutoff' => 10, 'stddev_killed' => 0, 'outwind_killed' => 0]]];
	$output = [
		"<row><timestamp>200</timestamp><v> 1000 </v></row>\n",
		"<row><timestamp>225</timestamp><v> NaN </v></row>\n",
	];
	$updated = (new ReflectionMethod(spikekill::class, 'updateXML'))->invokeArgs($repair, [&$output, &$statistics]);
	expect($updated[0])->toContain('<v> 1.0000000000e+1</v>')
		->and($updated[1])->toContain('<v> NaN</v>')
		->and($statistics[0][0]['stddev_killed'])->toBe(1)
		->and($statistics[0][0]['outwind_killed'])->toBe(1);
});
