<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

namespace RealtimeFieldLookupFailureTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/poller_realtime.php'), 'process_poller_output_rt'));
function cacti_log(...$args) {}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function read_config_option($key)
{
    return $GLOBALS['field_fixture'];
}
function get_data_source_path(...$args)
{
    return $GLOBALS['field_fixture'] . '/source.rrd';
}
function cacti_rrdtool_valid_path($path)
{
    return true;
}
function db_fetch_assoc_prepared($sql, $params)
{
    if (strpos($sql, 'SELECT DISTINCT') !== false) {
        return false;
    }
    return array(array('local_data_id' => 8, 'output' => 'metric:42', 'time' => '2026-09-15 00:00:00', 'rrd_name' => 'value'));
}
function db_execute_prepared(...$args)
{
    throw new \RuntimeException('Unprepared realtime sample was deleted');
}
function rrdtool_function_update(...$args)
{
    throw new \RuntimeException('Failed field mapping was converted to an RRD update');
}

test('failed realtime field mapping retains the original sample without writing fallback U', function () {
    $saved = $GLOBALS['config'] ?? null;
    $dir = sys_get_temp_dir() . '/rt-field-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    file_put_contents($dir . '/rrd.php', '<?php');
    file_put_contents($dir . '/user_1_8.rrd', 'existing');
    $GLOBALS['field_fixture'] = $dir;
    $GLOBALS['config'] = array('library_path' => $dir);
    try {
        expect(process_poller_output_rt(true, 1, 60))->toBeFalse();
    } finally {
        $GLOBALS['config'] = $saved;
        unlink($dir . '/rrd.php');
        unlink($dir . '/user_1_8.rrd');
        rmdir($dir);
    }
});
