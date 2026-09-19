<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WindowsProbeCleanup;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/rrd_maintenance.php'), 'rrd_maintenance_configuration_error'));
function read_config_option($key)
{
    return 0;
}
function __($message)
{
    return $message;
}
function fwrite($handle, $value)
{
    if ($GLOBALS['probe_mode'] === 'throw') {
        throw new \RuntimeException('injected write exception');
    }
    return \fwrite($handle, $value);
}
function unlink($path)
{
    $GLOBALS['probe_unlinks']++;
    if ($GLOBALS['probe_mode'] === 'unlink' && $GLOBALS['probe_unlinks'] === 1) {
        return false;
    }
    return \unlink($path);
}
test('Windows probes clean owned temporary files without hiding failures', function ($mode) {
    $directory = sys_get_temp_dir() . '/probe-cleanup-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $saved = $GLOBALS['config'] ?? null;
    $GLOBALS['config'] = array('cacti_server_os' => 'win32','rra_path' => $directory);
    $GLOBALS['probe_mode'] = $mode;
    $GLOBALS['probe_unlinks'] = 0;
    try {
        if ($mode === 'throw') {
            expect(fn() => rrd_maintenance_configuration_error())->toThrow(\RuntimeException::class, 'injected write exception');
        } else {
            expect(rrd_maintenance_configuration_error() === '')->toBe($mode === 'ok');
        }
        expect(glob($directory . '/.kadupul-write-*'))->toBe(array());
        expect($GLOBALS['probe_unlinks'])->toBe($mode === 'unlink' ? 2 : 1);
    } finally {
        foreach (glob($directory . '/.kadupul-write-*') as $file) {
            \unlink($file);
        }
        rmdir($directory);
        $GLOBALS['config'] = $saved;
    }
})->with(array('ok','unlink','throw'));
