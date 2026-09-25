<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace DataSourceLimitValidationTest;

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

// lib/functions.php cannot be loaded here, since other test files stub its
// functions, so the pattern matrix runs its real functions from source.
$functions = file_get_contents(dirname(__DIR__, 3) . '/lib/functions.php');
foreach (array('form_input_validate', 'is_error_message', 'data_source_limit_pattern') as $function) {
    eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($functions, $function));
}

function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function read_config_option($name)
{
    return '';
}
function raise_message(...$args) {}
function cacti_log(...$args) {}

function limit_accepted(array $tokens, string $value): bool
{
    $_SESSION = array();
    form_input_validate($value, 'rrd_minimum', data_source_limit_pattern($tokens), false, 3);

    return !is_error_message();
}

/**
 * Post one save to the real $page in a child process, with $fields over a
 * valid request, and return what it saved and which fields failed.
 */
function limit_save($test, string $page, array $fields): array
{
    $root = dirname(__DIR__, 3);
    $requests = array(
        'data_sources.php' => array(
            'save_component_data_source' => '1', 'local_data_id' => '5', 'data_template_id' => '0', '_data_template_id' => '0',
            'host_id' => '0', '_host_id' => '0', 'current_rrd' => '7', 'data_template_data_id' => '3',
            'local_data_template_data_id' => '0', 'data_input_id' => '1', '_data_input_id' => '1', 'name' => 'Traffic',
            'data_source_path' => 'rra/traffic_5.rrd', 'data_source_profile_id' => '1', 'rrd_step' => '300',
            'rrd_heartbeat' => '600', 'data_source_type_id' => '1', 'data_source_name' => 'value',
        ),
        'data_templates.php' => array(
            'save_component_template' => '1', 'data_template_id' => '4', 'data_template_data_id' => '3',
            'data_template_rrd_id' => '7', 'data_input_id' => '1', 'name' => 'Traffic', 'template_name' => 'Traffic',
            'data_source_profile_id' => '1', 'data_source_type_id' => '1', 'data_source_name' => 'value',
        ),
    );
    $request = $fields + array('action' => 'save', 'rrd_minimum' => '0', 'rrd_maximum' => 'U') + $requests[$page];
    $directory = sys_get_temp_dir() . '/limit-save-' . bin2hex(random_bytes(8));
    mkdir($directory . '/include', 0700, true);
    file_put_contents($directory . '/include/auth.php', '<?php');
    symlink($root . '/lib', $directory . '/lib');
    $coverage = $test->getTestResultObject()->getCodeCoverage();
    $environment = getenv();
    $environment['LIMIT_COVERAGE'] = $coverage === null ? '0' : '1';
    try {
        $process = proc_open(
            array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~',
                $root . '/tests/Fixtures/data-source-limit-save.php', $root, $page, json_encode($request)),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            $directory,
            $environment
        );
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $stderr)->and($stderr)->toBe('');
        if ($coverage !== null) {
            foreach (glob($directory . '/*.coverage') as $report) {
                $coverage->merge(unserialize(file_get_contents($report)));
            }
        }

        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        foreach (glob($directory . '/*.coverage') as $report) {
            unlink($report);
        }
        unlink($directory . '/lib');
        unlink($directory . '/include/auth.php');
        rmdir($directory . '/include');
        rmdir($directory);
    }
}

$numbers = array('0', '-5', '2.5', '.5', '5.', '1e3', '-2.5E-3', 'U');
$refused = array('5 x', '0;x', '1U', 'U 0', 'x U', "5\n", '-', '1e', '0x10', ' 0', '+5', 'u');

test('a limit pattern accepts a number or U and nothing around it', function ($value, $expected) {
    foreach (array(array(), array('ifSpeed'), array('ifSpeed', 'ifHighSpeed')) as $tokens) {
        expect(limit_accepted($tokens, $value))->toBe($expected);
    }
})->with(function () use ($numbers, $refused) {
    return array_merge(
        array_map(fn($value) => array($value, true), $numbers),
        array_map(fn($value) => array($value, false), $refused)
    );
});

test('a limit pattern accepts only the interface speed tokens it is given', function ($tokens, $value, $expected) {
    expect(limit_accepted($tokens, $value))->toBe($expected);
})->with(array(
    array(array(), '|query_ifSpeed|', false),
    array(array('ifSpeed'), '|query_ifSpeed|', true),
    array(array('ifSpeed'), '|query_ifHighSpeed|', false),
    array(array('ifSpeed', 'ifHighSpeed'), '|query_ifHighSpeed|', true),
    array(array('ifSpeed'), '|query_ifSpeed| x', false),
    array(array('ifSpeed'), 'x |query_ifSpeed|', false),
    array(array('ifSpeed'), '|query_ifSpeedx', false),
));

test('a data source page stores a valid minimum and maximum', function ($page, $fields) {
    $result = limit_save($this, $page, $fields);

    expect($result['errors'])->toBe(array());
    foreach ($fields as $field => $value) {
        expect($result['saved']['data_template_rrd'][$field])->toBe($value);
    }
})->with(array(
    array('data_sources.php', array('rrd_minimum' => '-2.5', 'rrd_maximum' => '|query_ifHighSpeed|')),
    array('data_sources.php', array('rrd_minimum' => 'U', 'rrd_maximum' => '1e9')),
    array('data_templates.php', array('rrd_minimum' => '-2.5', 'rrd_maximum' => '|query_ifSpeed|')),
    array('data_templates.php', array('rrd_minimum' => 'U', 'rrd_maximum' => '1e9')),
));

test('a data source page stores nothing for a limit that fails validation', function ($page, $field, $value) {
    $result = limit_save($this, $page, array($field => $value));

    expect($result['errors'])->toBe(array($field))
        ->and($result['saved'])->not->toHaveKey('data_template_rrd');
})->with(array(
    array('data_sources.php', 'rrd_minimum', '5 x'),
    array('data_sources.php', 'rrd_minimum', '0;x'),
    array('data_sources.php', 'rrd_minimum', '|query_ifSpeed|'),
    array('data_sources.php', 'rrd_maximum', '100 x'),
    array('data_templates.php', 'rrd_minimum', '5 x'),
    array('data_templates.php', 'rrd_minimum', '0;x'),
    array('data_templates.php', 'rrd_maximum', '|query_ifHighSpeed|'),
));
