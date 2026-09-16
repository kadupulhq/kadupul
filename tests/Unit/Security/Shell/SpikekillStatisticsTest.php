<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

require_once dirname(__DIR__, 4) . '/lib/spikekill.php';
if (!function_exists('cacti_sizeof')) {
    function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
}
if (!function_exists('number_format_i18n')) {
    function number_format_i18n($value, $decimals = 0) { return number_format($value, $decimals); }
}
if (!function_exists('__')) {
    function __($text, ...$args) { return $args ? vsprintf($text, $args) : $text; }
}

test('statistics preserve unavailable values and align all sixteen columns', function ($value, $expected, $html) {
    $class = new ReflectionClass(spikekill::class);
    $object = $class->newInstanceWithoutConstructor();
    $object->html = $html;
    foreach (array('step' => 60, 'rra_pdp' => array(1), 'ds_name' => array('value'), 'rra_cf' => array('AVERAGE')) as $name => $setting) {
        $property = $class->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($object, $setting);
    }
    $row = array('totalsamples' => 10, 'numsamples' => 3, 'stddev_killed' => 1, 'variance_killed' => 2, 'outwind_samples' => 3, 'outwind_killed' => 4);
    foreach (array('average', 'stddev', 'variance_avg', 'max_value', 'min_value', 'max_cutoff', 'min_cutoff') as $field) { if ($value !== '__missing__') { $row[$field] = $value; } }
    if ($value === '__empty__') {
        $row['numsamples'] = 0;
        foreach (array('average', 'stddev', 'variance_avg', 'max_value', 'min_value', 'max_cutoff', 'min_cutoff') as $field) { $row[$field] = 0; }
    }
    $method = $class->getMethod('outputStatistics');
    $method->setAccessible(true);
    $method->invoke($object, array(array($row)));
    $out = $object->get_output();
    if ($html) {
        preg_match_all('/<td[^>]*>(.*?)<\/td>/', $out, $matches);
        $cells = $matches[1];
    } else {
        preg_match('/^\s*1 mins\s+value.*$/m', $out, $match);
        expect($match)->not->toBeEmpty();
        $cells = array_merge(array('1 mins'), preg_split('/\s+/', trim(preg_replace('/^\s*1 mins\s+/', '', $match[0]))));
    }
    expect($cells)->toHaveCount(16)
        ->and(array_slice($cells, 5, 7))->toBe(array_fill(0, 7, $expected))
        ->and(array_slice($cells, 12))->toBe(array('1', '2', '3', '4'));
})->with(array(
    array('__empty__', 'N/A', false), array('__empty__', 'N/A', true), array('__missing__', 'N/A', false), array('__missing__', 'N/A', true),
    array('NAN', 'N/A', false), array('NAN', 'N/A', true),
    array(NAN, 'N/A', false), array(NAN, 'N/A', true),
    array(INF, 'N/A', false), array(-INF, 'N/A', true),
    array(null, 'N/A', false), array('N/A', 'N/A', true),
    array(12.345, '12.35', false), array(12.345, '12.35', true),
    array(100000000, '1.00e+8', false), array(100000000, '1.00e+8', true),
    array(-100000000, '-1.00e+8', false), array(-100000000, '-1.00e+8', true)
));
