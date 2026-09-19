<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('production storage coordination fails closed without identity and honors bounded lock policy', function ($mode) {
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/storage-coordination-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $bootstrap = '<?php require ' . var_export($root . '/lib/rrd_maintenance.php', true) . ';';
    $bootstrap .= '$mode=' . var_export($mode, true) . ';';
    $bootstrap .= <<<'SOURCE'
$config = array('cacti_server_os'=>'unix', 'rra_path'=>__DIR__);
function read_config_option($key) { return 0; }
function __($message) { return $message; }
if ($mode === 'no-posix') {
    echo json_encode(array(rrd_maintenance_directory_is_trusted(__DIR__), rrd_maintenance_configuration_error()));
} elseif ($mode === 'release') {
    foreach (array(null, false, true, 0, '') as $handle) { rrd_maintenance_release($handle); }
    $handle=rrd_maintenance_acquire(true);
    $acquired=is_resource($handle);
    rrd_maintenance_release($handle);
    rrd_maintenance_release($handle);
    echo json_encode(array($acquired, is_resource($handle)));
} else {
    $owner = rrd_maintenance_acquire(true);
    if (!is_resource($owner)) { throw new RuntimeException('Fixture could not acquire exclusive lease'); }
    $start = hrtime(true);
    try {
        $busy = null;
        $result = rrd_maintenance_acquire(false, false, $mode === 'immediate' ? 0 : null, $busy);
        $elapsed = (hrtime(true)-$start)/1000000000;
    } finally { rrd_maintenance_release($owner); }
    $retry = rrd_maintenance_acquire(false, false, 0);
    echo json_encode(array($result, $busy, $elapsed, is_resource($retry)));
    rrd_maintenance_release($retry);
}
SOURCE;
    file_put_contents($directory . '/run.php', $bootstrap);
    try {
        $command = array(PHP_BINARY);
        if ($mode === 'no-posix') {
            $command = array_merge($command, array('-d', 'disable_functions=posix_geteuid,posix_getegid'));
        }
        $command[] = $directory . '/run.php';
        $process = proc_open($command, array(1=>array('pipe','w'), 2=>array('pipe','w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error)->and($error)->toBe('');
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        if ($mode === 'release') {
            expect($result)->toBe(array(true, false));
        } elseif ($mode === 'no-posix') {
            expect($result[0])->toBeFalse();
            expect($result[1])->toContain('POSIX unavailable');
        } else {
            expect($result[0])->toBeFalse();
            expect($result[1])->toBeTrue()->and($result[3])->toBeTrue();
            expect($result[2])->toBeLessThan($mode === 'immediate' ? 1.0 : 8.0);
            if ($mode === 'bounded') { expect($result[2])->toBeGreaterThanOrEqual(4.5); }
        }
    } finally {
        unlink($directory . '/run.php');
        rmdir($directory);
    }
})->with(array('no-posix', 'immediate', 'bounded', 'release'));
