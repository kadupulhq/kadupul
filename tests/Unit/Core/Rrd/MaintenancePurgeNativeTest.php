<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/NativeChildCoverageEvidence.php';

test('native cleanup preserves failed requests and releases leases before metadata deletion', function ($mode, $action) {
    if (!function_exists('posix_geteuid') || ($mode === 'filesystem' && posix_geteuid() === 0)) {
        $this->markTestSkipped('Requires POSIX identity and non-root filesystem permissions.');
    }
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/native-purge-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    foreach (array('include', 'bad', 'archive', 'archive/bad') as $path) {
        mkdir($directory . '/' . $path, 0700);
    }
    file_put_contents($directory . '/bad/sample.rrd', 'retained original');
    file_put_contents($directory . '/good.rrd', 'valid sibling');
    copy($root . '/poller_maintenance.php', $directory . '/poller_maintenance.php');
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $bootstrap = '<?php ';
    if ($coverage !== null) {
        $bootstrap .= 'define("MAINTENANCE_PURGE_TEST_COVERAGE",true);' .
            'define("MAINTENANCE_PURGE_NATIVE_SCENARIO",' . var_export(json_encode([$mode, $action], JSON_THROW_ON_ERROR), true) . ');' .
            'define("RRD_TEST_COVERAGE_DIRECTORY",dirname(__DIR__));' .
            'define("RRD_TEST_CLI_COVERAGE_COPY",dirname(__DIR__)."/poller_maintenance.php");' .
            'define("RRD_TEST_CLI_COVERAGE_SOURCE",' . var_export($root . '/poller_maintenance.php', true) . ');' .
            'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $bootstrap .= 'require ' . var_export($root . '/lib/rrd_maintenance.php', true) . ';';
    $bootstrap .= '$mode=' . var_export($mode, true) . ';$action=' . var_export($action, true) . ';';
    $bootstrap .= <<<'SOURCE'
$config = array('cacti_server_os'=>'unix', 'rra_path'=>dirname(__DIR__), 'base_path'=>dirname(__DIR__));
$debug = false;
$archived = $purged = $reads = 0;
$poller_start = microtime(true);
$queue = array(array('id'=>1, 'name'=>'bad/sample.rrd', 'local_data_id'=>0, 'action'=>$action),
    array('id'=>2, 'name'=>'good.rrd', 'local_data_id'=>0, 'action'=>$action));
if ($mode === 'empty') { $queue = array(); }
if ($mode === 'directory-link') {
    mkdir(dirname(__DIR__).'/rra', 0700);
    symlink(dirname(__DIR__).'/bad', dirname(__DIR__).'/rra/bad');
    rename(dirname(__DIR__).'/good.rrd', dirname(__DIR__).'/rra/good.rrd');
    $config['rra_path'] = dirname(__DIR__).'/rra';
}
if ($mode === 'custom-root') {
    mkdir(dirname(__DIR__).'/rra', 0700);
    $config['rra_path'] = dirname(__DIR__).'/rra';
    $queue[0]['name'] = '<path_cacti>/bad/sample.rrd';
    $queue[1]['name'] = '<path_cacti>/good.rrd';
}
if ($mode === 'source-link') {
    rename(dirname(__DIR__).'/bad/sample.rrd', dirname(__DIR__).'/target.rrd');
    symlink(dirname(__DIR__).'/target.rrd', dirname(__DIR__).'/bad/sample.rrd');
}
if ($mode === 'archive-link') {
    mkdir(dirname(__DIR__).'/outside', 0700);
    rmdir(dirname(__DIR__).'/archive/bad');
    symlink(dirname(__DIR__).'/outside', dirname(__DIR__).'/archive/bad');
    mkdir(dirname(__DIR__).'/bad/new', 0700);
    rename(dirname(__DIR__).'/bad/sample.rrd', dirname(__DIR__).'/bad/new/sample.rrd');
    $queue[0]['name'] = 'bad/new/sample.rrd';
}
if ($mode === 'target-link') {
    file_put_contents(dirname(__DIR__).'/outside.rrd', 'outside original');
    symlink(dirname(__DIR__).'/outside.rrd', dirname(__DIR__).'/archive/bad/sample.rrd');
}
if ($mode === 'unresolved-archive') {
    rmdir(dirname(__DIR__).'/archive/bad');
    rmdir(dirname(__DIR__).'/archive');
    file_put_contents(dirname(__DIR__).'/archive', 'not a directory');
}
if ($mode === 'proxy-valid') {
    $queue[0]['name'] = '/host/sample.rrd';
    $queue[1]['name'] = '<path_rra>/host/other.rrd';
    $queue[] = ['id'=>3,'name'=>'<path_cacti>/host/cacti.rrd','local_data_id'=>0,'action'=>$action];
    $queue[] = ['id'=>4,'name'=>'host/bare.rrd','local_data_id'=>0,'action'=>$action];
}
if ($mode === 'proxy-unsafe') {
    $queue = [];
    foreach (['../x.rrd', '/etc/passwd', 'C:foo.rrd', 'a\\b.rrd', '<path_rra>/../x.rrd'] as $index => $name) {
        $queue[] = ['id' => $index + 1, 'name' => $name, 'local_data_id' => 0, 'action' => $action];
    }
}
$messages = $warnings = $deleted = $commands = array();
$closed = 0;
define('RRDTOOL_OUTPUT_BOOLEAN', 4);
function rrd_init(...$arguments) { return 'proxy-pipe'; }
function rrd_close(...$arguments) { $GLOBALS['closed']++; }
function rrdtool_execute($command, ...$arguments) {
    $GLOBALS['commands'][] = $command;
    return $command === 'setcnn timeout off' || $GLOBALS['mode'] !== 'proxy-failure';
}
function read_config_option($key, ...$args) { return $key === 'storage_location' ? (str_starts_with($GLOBALS['mode'], 'proxy-') ? 1 : 0) : ($key === 'rrd_archive' ? dirname(__DIR__).'/archive' : 0); }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_log($message, ...$args) { $GLOBALS['messages'][] = $message; }
function db_fetch_cell($sql) { return $GLOBALS['mode'] === 'count' ? false : count($GLOBALS['queue']); }
function db_fetch_assoc_prepared($sql, $params = array()) {
    if (strpos($sql, 'FROM data_source_purge_action') === false) { return array(); }
    if (++$GLOBALS['reads'] > 2) { throw new RuntimeException('Cleanup retried a failed batch indefinitely'); }
    if ($GLOBALS['mode'] === 'read') { return false; }
    // Model the keyset page after the last request seen.
    $rows = array_values(array_filter($GLOBALS['queue'], fn($row) => $row['name'] > $params[0] || ($row['name'] === $params[1] && $row['id'] > $params[2])));
    usort($rows, fn($left, $right) => [$left['name'], $left['id']] <=> [$right['name'], $right['id']]);
    return $rows;
}
function db_execute_prepared($sql, $params) {
    $writer = rrd_maintenance_acquire(false, false, 0);
    if (!is_resource($writer)) { throw new RuntimeException('Metadata mutation retained the exclusive lease'); }
    rrd_maintenance_release($writer);
    $GLOBALS['deleted'][] = $params[0];
    $GLOBALS['queue'] = array_values(array_filter($GLOBALS['queue'], fn($row) => $row['name'] !== $params[0]));
    return true;
}
function set_config_option(...$args) {}
set_error_handler(function ($number, $message) { $GLOBALS['warnings'][] = $message; return true; });
$writer = $mode === 'lease' ? rrd_maintenance_acquire(false) : null;
if ($mode === 'lease' && !is_resource($writer)) { throw new RuntimeException('Fixture could not acquire writer lease'); }
if ($mode === 'filesystem') { chmod(dirname(__DIR__).'/bad', 0500); }
try {
    $result = rrdfile_purge(false);
} finally {
    rrd_maintenance_release($writer);
    chmod(dirname(__DIR__).'/bad', 0700);
}
define('NATIVE_COVERAGE_COMPLETED', ['native-cleanup-observed']);
echo json_encode(array('result'=>$result, 'queue'=>$queue, 'deleted'=>$deleted, 'messages'=>$messages,
    'warnings'=>$warnings, 'purged'=>$purged, 'archived'=>$archived, 'commands'=>$commands, 'closed'=>$closed), JSON_THROW_ON_ERROR);
exit;
SOURCE;
    file_put_contents($directory . '/include/cli_check.php', $bootstrap);
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $directory . '/poller_maintenance.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error)->and($error)->toBe('');
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $completed = $mode === 'proxy-valid' ? 4 : (in_array($mode, ['success', 'directory-link', 'custom-root'], true) ? 2 : (in_array($mode, ['filesystem', 'source-link', 'archive-link', 'target-link'], true) ? 1 : 0));
        expect($result['deleted'])->toHaveCount($completed)
            ->and($result['queue'])->toHaveCount($mode === 'empty' ? 0 : ($mode === 'proxy-unsafe' ? 5 : ($mode === 'proxy-valid' ? 4 : 2)) - $completed)
            ->and($result[$action === '1' ? 'purged' : 'archived'])->toBe($completed);
        if (str_starts_with($mode, 'proxy-')) {
            expect($result['closed'])->toBe(1)->and($result['warnings'])->toBe([]);
            if ($mode === 'proxy-valid') {
                $operation = $action === '1' ? 'unlink' : 'archive';
                expect($result['commands'])->toBe(['setcnn timeout off', [$operation, 'host/sample.rrd'], [$operation, 'host/cacti.rrd'], [$operation, 'host/other.rrd'], [$operation, 'host/bare.rrd']]);
                expect($result['result'])->toBeTrue();
            } elseif ($mode === 'proxy-unsafe') {
                expect($result['commands'])->toBe(['setcnn timeout off'])->and($result['result'])->toBeFalse();
            } else {
                expect($result['commands'])->toHaveCount(2)->and($result['result'])->toBeFalse();
            }
            expect(file_get_contents($directory . '/bad/sample.rrd'))->toBe('retained original');
        } elseif (in_array($mode, ['success', 'directory-link', 'custom-root'], true)) {
            expect($result['result'])->toBeTrue()->and($result['warnings'])->toBe(array());
            expect(file_exists($directory . '/bad/sample.rrd'))->toBeFalse();
            if ($action === '3') {
                expect(file_get_contents($directory . '/archive/bad/sample.rrd'))->toBe('retained original');
            }
        } elseif ($mode === 'empty') {
            expect($result['result'])->toBeTrue()->and($result['warnings'])->toBe(array());
            expect(file_get_contents($directory . '/bad/sample.rrd'))->toBe('retained original');
        } else {
            expect($result['result'])->toBeFalse()
                ->and(file_get_contents($directory . ($mode === 'archive-link' ? '/bad/new/sample.rrd' : '/bad/sample.rrd')))->toBe('retained original');
            if ($mode === 'archive-link') {
                expect(file_exists($directory . '/outside/new'))->toBeFalse();
            }
            if ($mode === 'target-link') {
                expect(file_get_contents($directory . '/outside.rrd'))->toBe('outside original');
            }
            if ($mode === 'source-link') {
                expect(is_link($directory . '/bad/sample.rrd'))->toBeTrue()->and(file_get_contents($directory . '/target.rrd'))->toBe('retained original');
            }
            if ($mode === 'filesystem') {
                expect($result['deleted'])->toBe(array('good.rrd'))->and($result['warnings'])->toHaveCount(1)
                    ->and($result['messages'])->toContain('WARNING: RRDfile Maintenance retained 1 cleanup requests for retry.');
            } else {
                expect($result['warnings'])->toBe(array());
            }
        }
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $sources = ['composer.lock', 'tests/composer.lock', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php', 'poller_maintenance.php', 'tests/Unit/Core/Rrd/MaintenancePurgeNativeTest.php'];
            $arguments = [$reports[0], $root, 'tests/Unit/Core/Rrd/MaintenancePurgeNativeTest.php', json_encode([$mode, $action], JSON_THROW_ON_ERROR), $sources, ['native-cleanup-observed'], ['poller_maintenance.php']];
            $child = NativeChildCoverageEvidence::load(...$arguments);
            if ($mode === 'directory-link' && $action === '3') {
                expect(NativeChildCoverageEvidence::verifyRejections(...[...$arguments, 'lib/boost.php']))->toBe(count($sources) + 11);
            }
            $coverage->merge($child);
        }
    } finally {
        chmod($directory . '/bad', 0700);
        $paths = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($paths as $path) {
            !$path->isLink() && $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($directory);
    }
})->with([['count','1'],['count','3'],['read','1'],['read','3'],['lease','1'],['lease','3'],['filesystem','1'],['filesystem','3'],['success','1'],['success','3'],['empty','1'],['empty','3'],['directory-link','1'],['directory-link','3'],['source-link','1'],['source-link','3'],['archive-link','3'],['target-link','3'],['unresolved-archive','3'],['custom-root','1'],['custom-root','3'],['proxy-valid','1'],['proxy-valid','3'],['proxy-unsafe','1'],['proxy-unsafe','3'],['proxy-failure','1'],['proxy-failure','3']]);
