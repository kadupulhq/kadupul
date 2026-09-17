<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

list(, $root, $directory, $mode, $collect) = $argv;
if ($collect === '1') {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/lib/rrd_maintenance.php';
$config = array('cacti_server_os' => 'unix', 'rra_path' => $directory);
$messages = array();
$calls = 0;
function cacti_log($message, ...$args)
{
    $GLOBALS['messages'][] = $message;
}
function read_config_option($key)
{
    return $GLOBALS['mode'] === 'remote-unsafe' && $key === 'storage_location' ? 1 : 0;
}
function __($message, ...$args)
{
    return $message;
}
$xml = $directory . '/recovery.xml';
$rrd = $directory . '/live.rrd';
file_put_contents($xml, 'retained recovery');
file_put_contents($rrd, 'retained original');
// Inject syscall failures only in dedicated child processes. Production restore
// code and cleanup execute unchanged; these are not real disk-exhaustion tests.
if (in_array($mode, array('temporary-failure', 'temporary-outside'), true)) {
    function tempnam($path, $prefix)
    {
        if ($GLOBALS['mode'] === 'temporary-failure') {
            return false;
        }
        $outside = $path . '/outside';
        mkdir($outside, 0700);
        $file = $outside . '/temporary';
        file_put_contents($file, '');
        return $file;
    }
}
if ($mode === 'mode-failure') {
    function chmod($path, $permissions)
    {
        if (strpos(basename($path), '.rrd-restore-') !== 0) {
            throw new RuntimeException('Unexpected permission mutation');
        }
        return false;
    }
}
if ($mode === 'process-failure') {
    function proc_open(...$args)
    {
        return false;
    }
}
if ($mode === 'no-posix') {
    $result = rrd_maintenance_directory_is_trusted($directory);
    $messages[] = rrd_maintenance_configuration_error();
} elseif (in_array($mode, array('workspace-untrusted', 'workspace-failure'), true)) {
    if ($mode === 'workspace-untrusted') {
        $config['rrd_maintenance_trusted_uids'] = 'invalid configuration';
    }
    $result = rrd_maintenance_workspace();
} elseif ($mode === 'process-failure') {
    $command = rrd_maintenance_run_command(array(PHP_BINARY, '-v'), null);
    if ($command !== array('exit' => false, 'stdout' => '', 'stderr' => '')) {
        throw new RuntimeException('Process creation failure was not reported faithfully');
    }
    $result = $command['exit'];
} elseif ($mode === 'remote-unsafe') {
    $result = rrd_maintenance_restore($xml . "\n", $rrd, array('proxy'));
} else {
    $target = $rrd;
    if ($mode === 'unsafe-path') {
        $target .= "\n";
    } elseif ($mode === 'symlink-target') {
        $target = $directory . '/link.rrd';
        symlink($rrd, $target);
    } elseif ($mode === 'rename-failure') {
        $target = $directory . '/directory.rrd';
        mkdir($target, 0700);
        file_put_contents($target . '/retained', 'retained directory');
    }
    $result = rrd_maintenance_restore_atomic($xml, $target, function ($temporary) use ($mode, $rrd, &$calls) {
        $calls++;
        if ($mode === 'missing-output') {
            unlink($temporary);
        } elseif ($mode === 'symlink-output') {
            unlink($temporary);
            symlink($rrd, $temporary);
        } elseif (in_array($mode, array('rename-failure', 'mode-failure'), true)) {
            file_put_contents($temporary, 'replacement');
        }
        return true;
    });
}
echo json_encode(array('result' => $result, 'calls' => $calls, 'messages' => $messages,
    'original' => file_get_contents($rrd), 'recovery' => file_get_contents($xml),
    'temporary' => glob($directory . '/.rrd-restore-*')), JSON_THROW_ON_ERROR);
