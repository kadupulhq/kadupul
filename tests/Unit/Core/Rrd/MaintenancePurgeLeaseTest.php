<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace MaintenancePurgeLeaseTest;

require_once dirname(__DIR__, 4) . '/lib/rrd_maintenance.php';
require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
$source = file_get_contents(dirname(__DIR__, 4) . '/poller_maintenance.php');
foreach (array('rrdfile_purge', 'remove_files', 'rrdclean_create_path') as $name) {
    eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, $name));
}
function read_config_option($key, $force = false)
{
    return $key === 'rrd_archive' ? $GLOBALS['config']['rra_path'] . '/archive' : 0;
}
function maint_debug(...$args) {}
function cacti_log(...$args) {}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function db_fetch_cell($sql)
{
    return 1;
}
function db_fetch_assoc($sql)
{
    if (!$GLOBALS['purge_fixture_queue']) {
        return array();
    }
    if (++$GLOBALS['purge_fixture_reads'] > 1) {
        throw new \RuntimeException('deferred queue looped');
    }
    return $GLOBALS['purge_fixture_queue'];
}
function db_fetch_assoc_prepared(...$args)
{
    return array();
}
function db_execute_prepared(...$args)
{
    // Metadata cleanup must run after the file lease is released.
    $writer = \rrd_maintenance_acquire(false, false, 0);
    expect(is_resource($writer))->toBeTrue();
    \rrd_maintenance_release($writer);
    $GLOBALS['purge_fixture_queue'] = array_values(array_filter($GLOBALS['purge_fixture_queue'], static function ($row) use ($args) {
        return $row['name'] !== $args[1][0];
    }));
    return true;
}
function set_config_option(...$args) {}
function unlink($path)
{
    return !empty($GLOBALS['purge_fixture_failure']) && basename($path) === 'sample.rrd' ? false : \unlink($path);
}
function rename($source, $target)
{
    return !empty($GLOBALS['purge_fixture_failure']) && basename($source) === 'sample.rrd' ? false : \rename($source, $target);
}

test('purge and archive defer once under a writer lease then complete on retry', function ($action, $filesystemFailure) {
    $saved = $GLOBALS['config'] ?? null;
    $directory = sys_get_temp_dir() . '/purge-lease-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    file_put_contents($directory . '/sample.rrd', 'original');
    $GLOBALS['config'] = array('cacti_server_os' => 'unix', 'rra_path' => $directory, 'base_path' => $directory);
    $GLOBALS['purged'] = $GLOBALS['archived'] = 0;
    $GLOBALS['poller_start'] = microtime(true);
    $GLOBALS['purge_fixture_queue'] = array(array('id' => 1, 'name' => 'sample.rrd', 'local_data_id' => 0, 'action' => $action));
    $GLOBALS['purge_fixture_reads'] = 0;
    $writer = \rrd_maintenance_acquire(false);
    try {
        expect(rrdfile_purge(false))->toBeFalse()->and($GLOBALS['purge_fixture_reads'])->toBe(1)
            ->and(file_get_contents($directory . '/sample.rrd'))->toBe('original')
            ->and($GLOBALS['purge_fixture_queue'])->toHaveCount(1);
        \rrd_maintenance_release($writer);
        $GLOBALS['purge_fixture_reads'] = 0;
        if ($filesystemFailure) {
            file_put_contents($directory . '/good.rrd', 'valid sibling');
            $GLOBALS['purge_fixture_queue'][] = array('id' => 2, 'name' => 'good.rrd', 'local_data_id' => 0, 'action' => $action);
            $GLOBALS['purge_fixture_failure'] = true;
            expect(rrdfile_purge(false))->toBeFalse()
                ->and($GLOBALS['purge_fixture_queue'])->toHaveCount(1)
                ->and(file_exists($directory . '/sample.rrd'))->toBeTrue()
                ->and(file_exists($directory . '/good.rrd'))->toBeFalse();
            unset($GLOBALS['purge_fixture_failure']);
            $GLOBALS['purge_fixture_reads'] = 0;
        }
        expect(rrdfile_purge(false))->not->toBeFalse()->and($GLOBALS['purge_fixture_queue'])->toBe(array())
            ->and(file_exists($directory . '/sample.rrd'))->toBeFalse();
        if ($action === '3') {
            expect(file_get_contents($directory . '/archive/sample.rrd'))->toBe('original');
        }
    } finally {
        \rrd_maintenance_release($writer);
        unset($GLOBALS['purge_fixture_failure']);
        $GLOBALS['config'] = $saved;
        $paths = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($paths as $path) {
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($directory);
        unset($GLOBALS['purge_fixture_queue'], $GLOBALS['purge_fixture_reads']);
    }
})->with(array('1', '3'))->with(array(false, true));
