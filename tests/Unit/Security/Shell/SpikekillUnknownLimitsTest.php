<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * #1095: an RRD data source without a minimum or maximum dumps them as NaN.
 * The standard-deviation cutoffs ignore an unknown limit instead of comparing
 * a number with the string 'NaN' and taking it as the cutoff.
 */

require_once dirname(__DIR__, 4) . '/lib/spikekill.php';
if (!function_exists('cacti_sizeof')) {
    function cacti_sizeof($value)
    {
        return is_array($value) ? count($value) : 0;
    }
}

function spikekill_limit_cutoffs($minimum, $maximum): array
{
    $repair = (new ReflectionClass(spikekill::class))->newInstanceWithoutConstructor();
    $repair->method = SPIKE_METHOD_STDDEV;
    $repair->out_start = 0;
    $repair->out_end = 0;
    $repair->stddev = 1;
    $repair->percent = 0;
    foreach (['ds_min' => [$minimum], 'ds_max' => [$maximum]] as $name => $value) {
        (new ReflectionProperty(spikekill::class, $name))->setValue($repair, $value);
    }
    $statistics = [[['sumofsamples' => 60, 'numsamples' => 3]]];
    $samples = [[[100 => '10', 200 => '20', 300 => '30']]];
    (new ReflectionMethod(spikekill::class, 'calculateOverallStatistics'))->invokeArgs($repair, [&$statistics, &$samples]);

    return [$statistics[0][0]['min_cutoff'], $statistics[0][0]['max_cutoff']];
}

test('unknown limits leave the computed cutoffs in place', function () {
    [$minimum, $maximum] = spikekill_limit_cutoffs('NaN', 'NaN');

    expect($minimum)->toBeFloat()->and($maximum)->toBeFloat()
        ->and($minimum)->toBeLessThan(20)->and($maximum)->toBeGreaterThan(20);
});

test('numeric limits still clamp the cutoffs', function () {
    expect(spikekill_limit_cutoffs(15, 25))->toEqual([15, 25]);
});
