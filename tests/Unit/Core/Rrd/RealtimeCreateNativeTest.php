<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Run a copy of poller_realtime.php against the real lib/rrd.php and RRDtool,
 * with $minimum and $heartbeat stored for data source 12; data source 11 is
 * always valid. Returns the exit status, stdout and stderr; the copy's
 * directory is left for the caller to inspect.
 */
function realtime_create_run($test, string $directory, string $minimum, string $heartbeat = '600'): array
{
    $root = dirname(__DIR__, 4);
    $coverage = $test->getTestResultObject()->getCodeCoverage();
    $environment = getenv();
    $environment['REALTIME_ROOT'] = $root;
    $environment['REALTIME_MINIMUM'] = $minimum;
    $environment['REALTIME_HEARTBEAT'] = $heartbeat;
    // RRDtool reads the create text's --start 0 as midnight, so the sample is
    // taken now, and never at midnight itself.
    $environment['REALTIME_TIME'] = $test->sampleTime = gmdate('Y-m-d H:i:s', max(time(), strtotime('today UTC') + 1));
    $environment['REALTIME_COVERAGE'] = $coverage === null ? '0' : '1';
    $process = proc_open(
        array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=/',
            '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $directory . '/poller_realtime.php',
            '--graph=7', '--interval=10', '--poller_id=abc123'),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        $directory,
        $environment
    );
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($coverage !== null) {
        foreach (glob($directory . '/*.coverage') as $report) {
            $coverage->merge(unserialize(file_get_contents($report)));
        }
    }

    return array($status, $stdout, $stderr);
}

beforeEach(function () {
    $binary = getenv('RRDTOOL_TEST_BINARY') ?: (is_executable('/usr/bin/rrdtool') ? '/usr/bin/rrdtool' : '/opt/homebrew/bin/rrdtool');
    if (!is_executable($binary)) {
        $this->markTestSkipped('Real RRDtool is required; CI provisions it.');
    }
    $this->binary = $binary;
    $root = dirname(__DIR__, 4);
    $this->dir = realpath(sys_get_temp_dir()) . '/realtime-create-' . bin2hex(random_bytes(8));
    foreach (array('', '/include', '/lib', '/cache', '/rra') as $suffix) {
        mkdir($this->dir . $suffix, 0700);
    }
    copy($root . '/poller_realtime.php', $this->dir . '/poller_realtime.php');
    file_put_contents($this->dir . '/include/cli_check.php', '<?php require ' . var_export($root . '/tests/Fixtures/realtime-create-native.php', true) . ';');
    foreach (array('poller', 'data_query', 'rrd') as $library) {
        file_put_contents($this->dir . '/lib/' . $library . '.php', '<?php');
    }
    // A blank in the binary's path is harmless to an argument list and splits a shell command.
    symlink($binary, $this->dir . '/rrd tool');
});

afterEach(function () {
    if (!isset($this->dir) || !is_dir($this->dir)) {
        return;
    }
    foreach (array_merge(glob($this->dir . '/*/*'), glob($this->dir . '/*')) as $file) {
        is_dir($file) && !is_link($file) ? rmdir($file) : unlink($file);
    }
    rmdir($this->dir);
});

/** The deletions the poller made, one per acknowledged sample. */
function realtime_create_deleted($test): array
{
    $file = $test->dir . '/deleted.json';

    return is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : array();
}

/** ERROR lines the poller logged. */
function realtime_create_errors($test): array
{
    $file = $test->dir . '/cacti.log';

    return is_file($file) ? array_values(preg_grep('/ERROR:/', file($file, FILE_IGNORE_NEW_LINES))) : array();
}

function realtime_create_sample($test, string $local_data_id): string
{
    return json_encode(array($local_data_id, 'value', $test->sampleTime, 'abc123', '42'));
}

test('the realtime poller creates its RRDs through the RRDtool pipe with the realtime step', function () {
    list($status, $stdout, $stderr) = realtime_create_run($this, $this->dir, '0');

    expect($stderr)->toBe('')
        ->and($status)->toBe(0, implode("\n", realtime_create_errors($this)))
        ->and(realtime_create_errors($this))->toBe(array());
    foreach (array('11', '12') as $local_data_id) {
        $created = $this->dir . '/cache/user_abc123_' . $local_data_id . '.rrd';
        expect(is_file($created))->toBeTrue($stdout);
        $info = array();
        exec(escapeshellarg($this->binary) . ' info ' . escapeshellarg($created), $info);
        expect($info)->toContain('step = 10', 'ds[value].min = 0.0000000000e+00');
    }
    expect(realtime_create_deleted($this))->toBe(array(realtime_create_sample($this, '11'), realtime_create_sample($this, '12')))
        // The poller never falls back to the data source's own RRD.
        ->and(glob($this->dir . '/rra/*'))->toBe(array());
});

test('a realtime RRD that cannot be created keeps its sample and not the others', function ($minimum, $heartbeat, $error) {
    list($status, $stdout, $stderr) = realtime_create_run($this, $this->dir, $minimum, $heartbeat);

    // The marker would appear only if the value had been handed to a shell.
    expect(glob($this->dir . '/marker*'))->toBe(array())
        ->and($stderr)->toBe('')
        ->and($status)->toBe(0, $stdout)
        ->and(glob($this->dir . '/cache/*'))->toBe(array($this->dir . '/cache/user_abc123_11.rrd'))
        ->and(glob($this->dir . '/rra/*'))->toBe(array())
        ->and(realtime_create_deleted($this))->toBe(array(realtime_create_sample($this, '11')));
    // One refusal, not a second one from the update retrying the create.
    $errors = realtime_create_errors($this);
    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain($error);
})->with(array(
    'marker' => array('0;touch marker;', '600', 'RRD file for Data Source 12 was not created. Its minimum is not a number or U.'),
    'trailing text' => array('5 x', '600', 'RRD file for Data Source 12 was not created. Its minimum is not a number or U.'),
    'refused by RRDtool' => array('0', '0', 'Realtime RRD for Data Source 12 was not created. RRDtool refused it.'),
));
