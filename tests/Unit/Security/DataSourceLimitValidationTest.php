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
function limit_save($test, string $page, array $fields, array $environment_overrides = array()): array
{
    $root = dirname(__DIR__, 3);
    $requests = array(
        'data_sources.php' => array(
            'save_component_data_source' => '1', 'local_data_id' => '5', 'data_template_id' => '0', '_data_template_id' => '0',
            'host_id' => '0', '_host_id' => '0', 'current_rrd' => '7', 'data_template_data_id' => '3',
            'local_data_template_data_id' => '0', 'data_input_id' => '1', '_data_input_id' => '1', 'name' => 'Traffic',
            'data_source_path' => '<path_rra>/traffic_5.rrd', 'data_source_profile_id' => '1', 'rrd_step' => '300',
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
    $environment = $environment_overrides + $environment;
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

test('one data source item that fails validation does not stop the others from saving', function () {
    // Item 7 has a bad minimum; item 8, validated after it, is valid and must still be stored.
    $result = limit_save($this, 'data_sources.php', array(
        '_data_template_id' => '2', 'data_template_id' => '2', '__rrd_ids' => array('7', '8'),
        'rrd_minimum_7' => '5 x', 'rrd_maximum_7' => 'U', 'rrd_heartbeat_7' => '600', 'data_source_type_id_7' => '1', 'data_source_name_7' => 'in',
        'rrd_minimum_8' => '0', 'rrd_maximum_8' => '100', 'rrd_heartbeat_8' => '600', 'data_source_type_id_8' => '1', 'data_source_name_8' => 'out',
    ));

    expect($result['errors'])->toBe(array('rrd_minimum_7'))
        ->and(array_column($result['saved_all']['data_template_rrd'] ?? array(), 'id'))->toBe(array('8'));
});

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

test('a data source save for a device outside the user\'s scope stores nothing', function () {
    $result = limit_save($this, 'data_sources.php', array(), array('LIMIT_DEVICE_DENIED' => '1', 'LIMIT_SOURCE_HOST' => '12'));

    expect($result['saved'])->toBe(array());
});

test('a data source save naming another data source\'s rows stores nothing', function ($field) {
    $result = limit_save($this, 'data_sources.php', array($field => '9'), array('LIMIT_ROW_OWNER' => '6'));

    expect($result['saved'])->toBe(array());
})->with(array('data_template_data_id', 'current_rrd'));

test('a new data source without a device or template still saves', function () {
    $result = limit_save($this, 'data_sources.php', array(
        'local_data_id' => '0', 'data_template_data_id' => '0', 'current_rrd' => '0', 'save_component_data' => '1',
    ));

    expect($result['errors'])->toBe(array())
        ->and($result['saved'])->toHaveKeys(array('data_local', 'data_template_data', 'data_template_rrd'));
});


test('RRD save paths preserve a configured symlink root and reject repeated placeholders', function ($case, $accepted) {
    $directory = sys_get_temp_dir() . '/rrd-root-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    mkdir($directory . '/actual', 0700);
    symlink($directory . '/actual', $directory . '/configured');
    try {
        symlink($directory, $directory . '/actual/escape');
        $path = match ($case) {
            'configured' => $directory . '/configured/new.rrd',
            'canonical' => realpath($directory . '/actual') . '/new.rrd',
            'token' => '<path_rra>/new.rrd',
            'nested' => '<path_rra>/missing/sub/new.rrd',
            'bare' => 'new.rrd',
            'outside' => $directory . '/new.rrd',
            'nul' => '<path_rra>/new' . chr(0) . '.rrd',
            'backslash' => '<path_rra>/new' . chr(92) . '.rrd',
            'embedded' => 'prefix<path_rra>/new.rrd',
            'baretoken' => '<path_rra>',
            'repeat' => '<path_rra>/<path_rra>/new.rrd',
            'traversal' => '<path_rra>/../new.rrd',
            'escape' => '<path_rra>/escape/new.rrd',
        };
        $result = limit_save($this, 'data_sources.php', array('data_source_path' => $path), array('LIMIT_RRA_PATH' => $directory . '/configured'));
        if ($accepted) {
            expect($result['errors'])->toBe(array())
                ->and($result['saved']['data_template_data']['data_source_path'] ?? null)->toBe($path);
        } else {
            expect($result['saved'])->toBe(array())
                ->and($result['errors'])->toBe(array('data_source_path'));
        }
    } finally {
        unlink($directory . '/actual/escape');
        unlink($directory . '/configured');
        rmdir($directory . '/actual');
        rmdir($directory);
    }
})->with(array('configured root' => array('configured', true), 'canonical root' => array('canonical', true), 'placeholder' => array('token', true), 'repeated placeholder' => array('repeat', false), 'parent traversal' => array('traversal', false), 'escaping symlink' => array('escape', false), 'nested missing directory' => array('nested', true), 'bare filename' => array('bare', true), 'outside absolute' => array('outside', false), 'NUL byte' => array('nul', false), 'backslash' => array('backslash', false), 'embedded token' => array('embedded', false), 'bare token' => array('baretoken', false)));


test('the edit plugin runs only after existing or new source authorization', function ($id, $host, $denied, $admitted) {
    $result = limit_save($this, 'data_sources.php', array('action' => 'ds_edit', 'id' => $id, 'host_id' => $host), array('LIMIT_EDIT_HOOK' => '1', 'LIMIT_SOURCE_HOST' => $host, 'LIMIT_DEVICE_DENIED' => $denied ? '1' : '0'));
    expect($result['hooks'])->toBe($admitted ? array(array('data_source_edit_top')) : array())
        ->and($result['saved'])->toBe(array());
})->with(array(
    'existing admitted' => array('5', '12', false, true),
    'existing denied' => array('5', '13', true, false),
    'existing non-device without visible devices' => array('5', '0', true, true),
    'new admitted' => array('0', '12', false, true),
    'new denied' => array('0', '13', true, false),
    'new non-device without visible devices' => array('0', '0', true, true),
    'new negative target' => array('0', '-1', false, false),
));


test('an existing non-device source can save without any visible device', function () {
    $result = limit_save($this, 'data_sources.php', array(), array('LIMIT_DEVICE_DENIED' => '1'));
    expect($result['saved'])->toHaveKeys(array('data_local', 'data_template_data', 'data_template_rrd'));
});

test('a negative destination device stops the save independently of source authorization', function () {
    $result = limit_save($this, 'data_sources.php', array('host_id' => '-1'), array('LIMIT_SOURCE_HOST' => '0'));
    expect($result['saved'])->toBe(array());
});

test('RRD row ownership is checked independently of the owned data row', function ($templated) {
    $fields = array('current_rrd' => '9');
    if ($templated) {
        $fields['_data_template_id'] = $fields['data_template_id'] = '2';
    }
    $result = limit_save($this, 'data_sources.php', $fields, array('LIMIT_RRD_OWNER' => '6', 'LIMIT_DATA_OWNER' => '5'));
    if ($templated) {
        expect($result['saved'])->toHaveKeys(array('data_local', 'data_template_data'));
    } else {
        expect($result['saved'])->toBe(array());
    }
})->with(array('untemplated rejects foreign item' => array(false), 'templated ignores unused current item' => array(true)));


// These recording-port controller cases are behavioral-only. The full native
// HTTP/policy fixtures provide separately bound physical authorization proof.
test('component input authorization reaches its own owner guard before any replacement', function ($fields, $environment, $writes, $lookups) {
    $request = array('save_component_data_source' => null, 'save_component_data' => '1', 'value_7' => 'admitted value') + $fields;
    $result = limit_save($this, 'data_sources.php', $request, array('LIMIT_COVERAGE' => '0', 'LIMIT_COMPONENT_FIELD' => '1') + $environment);
    expect($result['saved'])->toBe(array())
        ->and(count($result['component_writes']))->toBe($writes)
        ->and(count($result['component_lookups']))->toBe($lookups);
    if ($writes) {
        expect($result['component_writes'][0][0])->toContain('REPLACE INTO data_input_data')
            ->and($result['component_writes'][0][1])->toBe(array(7, 3, 'admitted value'));
    }
})->with(array(
    'foreign row independently denied' => array(array(), array('LIMIT_DATA_OWNER' => '6'), 0, 1),
    'nonempty row without local source' => array(array('local_data_id' => '0'), array('LIMIT_DATA_OWNER' => '5'), 0, 1),
    'matching row on denied device' => array(array(), array('LIMIT_SOURCE_HOST' => '12', 'LIMIT_DEVICE_DENIED' => '1'), 0, 1),
    'missing data row' => array(array(), array('LIMIT_COMPONENT_MISSING' => '1'), 0, 1),
    'matching admitted row writes its input' => array(array(), array('LIMIT_DATA_OWNER' => '5'), 1, 1),
    'empty new data row remains admitted noop' => array(array('local_data_id' => '0', 'data_template_data_id' => '0'), array(), 0, 0),
));

test('existing source saves distinguish allowed denied and missing positive destination devices', function ($environment, $admitted) {
    $result = limit_save($this, 'data_sources.php', array('host_id' => '12', '_host_id' => '12'), array('LIMIT_COVERAGE' => '0') + $environment);
    if ($admitted) {
        expect($result['saved'])->toHaveKeys(array('data_local', 'data_template_data', 'data_template_rrd'))
            ->and($result['saved']['data_local']['host_id'])->toBe(12);
    } else {
        expect($result['saved'])->toBe(array())->and($result['component_writes'])->toBe(array());
    }
})->with(array(
    'allowed positive destination' => array(array(), true),
    'denied positive destination' => array(array('LIMIT_DEVICE_DENIED' => '1'), false),
    'missing positive destination' => array(array('LIMIT_DEVICE_MISSING' => '1'), false),
));
