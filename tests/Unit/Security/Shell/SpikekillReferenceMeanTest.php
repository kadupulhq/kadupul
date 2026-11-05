<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * #1034: with a replacement window, the standard deviation already uses only
 * the samples outside the window; the mean now uses the same samples, so the
 * cutoffs come from one reference set. Mirrors upstream Cacti #8428.
 */

require_once dirname(__DIR__, 4) . '/lib/spikekill.php';
if (!function_exists('cacti_sizeof')) {
    function cacti_sizeof($value)
    {
        return is_array($value) ? count($value) : 0;
    }
}

function spikekill_reference_statistics(int $start, int $end, array $samples): array
{
    $repair = (new ReflectionClass(spikekill::class))->newInstanceWithoutConstructor();
    $repair->method = SPIKE_METHOD_STDDEV;
    $repair->out_start = $start;
    $repair->out_end = $end;
    $repair->stddev = 2;
    $repair->percent = 0;
    foreach (['ds_min' => [0], 'ds_max' => [10000]] as $name => $value) {
        (new ReflectionProperty(spikekill::class, $name))->setValue($repair, $value);
    }
    $numeric = array_filter($samples, 'is_numeric');
    $statistics = [[['sumofsamples' => array_sum($numeric), 'numsamples' => count($numeric)]]];
    $wrapped = [[$samples]];
    (new ReflectionMethod(spikekill::class, 'calculateOverallStatistics'))->invokeArgs($repair, [&$statistics, &$wrapped]);

    return $statistics[0][0];
}

test('a windowed deviation uses the mean of the samples around the window', function () {
    $statistics = spikekill_reference_statistics(150, 250, [100 => '10', 200 => '1000', 225 => 'NaN', 300 => '10', 400 => 'NaN']);

    expect($statistics['average'])->toEqual(10)
        ->and($statistics['stddev'])->toEqual(0)
        ->and($statistics['min_cutoff'])->toEqual(10)
        ->and($statistics['max_cutoff'])->toEqual(10);
});

test('without a window, or with no samples around it, the overall mean is kept', function (int $start, int $end) {
    expect(spikekill_reference_statistics($start, $end, [100 => '10', 200 => '1000', 300 => '10'])['average'])->toEqual(340);
})->with(['no window' => [0, 0], 'window covers every sample' => [1, 400]]);
