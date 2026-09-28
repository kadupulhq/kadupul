<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

function runResourceCacheReplicationProbe($coverage): array
{
    $root      = dirname(__DIR__, 3);
    $directory = sys_get_temp_dir() . '/resource-cache-replication-' . bin2hex(random_bytes(8));
    $install   = $directory . '/install';
    $outside   = $directory . '/outside';

    mkdir($install . '/include', 0700, true);
    mkdir($outside, 0700, true);
    file_put_contents($install . '/include/config.php', 'local database secret');
    file_put_contents($install . '/source.txt', 'safe resource');
    file_put_contents($outside . '/outside.rrd', 'outside data');
    symlink($outside . '/outside.rrd', $install . '/linked.rrd');

    $phpPath = $directory . '/php binary';
    if (!symlink(PHP_BINARY, $phpPath)) {
        throw new RuntimeException('Unable to create PHP path containing spaces');
    }

    $program = <<<'PHP'
<?php
$root = $argv[1];
$install = $argv[2];
$outside = $argv[3];
$phpPath = $argv[4];
$GLOBALS['phpPath'] = $phpPath;
$config = array('base_path' => $install);
$remote_db_cnn_id = 1;
$GLOBALS['resource_cache_saves'] = array();
$GLOBALS['resource_cache_logs'] = array();
$GLOBALS['resource_cache_entries'] = array(
    array('id' => 1, 'path' => '../../outside/outside.rrd', 'md5sum' => 'cached', 'attributes' => 33188),
    array('id' => 2, 'path' => 'include/config.php', 'md5sum' => 'cached', 'attributes' => 33188),
    array('id' => 3, 'path' => 'linked.rrd', 'md5sum' => 'cached', 'attributes' => 33188),
    array('id' => 4, 'path' => 'safe.php', 'md5sum' => 'cached', 'attributes' => 33188),
);

function cacti_path_is_within($candidate, $base)
{
    $resolved = realpath($candidate);
    $root = realpath($base);

    return $resolved !== false && $root !== false && ($resolved === $root || strpos($resolved, $root . DIRECTORY_SEPARATOR) === 0);
}
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function read_config_option($name) { return $name === 'path_php_binary' ? $GLOBALS['phpPath'] : 'old-checksum'; }
function db_fetch_assoc_prepared(...$args) { return $GLOBALS['resource_cache_entries']; }
function db_fetch_cell_prepared($sql, $parameters = array(), ...$args) {
    if (strpos($sql, 'SELECT contents') !== false) {
        return base64_encode('<?php echo "valid";');
    }

    return 0;
}
function sql_save($row, $table) { $GLOBALS['resource_cache_saves'][] = $row; }
function cacti_log($message, ...$args) { $GLOBALS['resource_cache_logs'][] = $message; }

if ($argv[5] === 'coverage') {
    define('RESOURCE_CACHE_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $argv[6]);
    require $root . '/tests/Fixtures/rrd-process-coverage.php';
}
require $root . '/lib/poller.php';

$configPath = realpath($install . '/include/config.php');
$installPath = realpath($install);
$destinations = array(
    poller_resource_cache_destination('safe.php', $installPath, $configPath),
    poller_resource_cache_destination('../../outside/outside.rrd', $installPath, $configPath),
    poller_resource_cache_destination('linked.rrd', $installPath, $configPath),
    poller_resource_cache_destination('include/config.php', $installPath, $configPath),
    poller_resource_cache_destination('include/config.php', $installPath, false),
);

update_db_from_path($outside . '/outside.rrd', 'test', false);
update_db_from_path($install . '/source.txt', 'test', false);
resource_cache_out('test', array('path' => $install, 'recursive' => true));

echo json_encode(array(
    'destinations' => $destinations,
    'saved_paths' => array_column($GLOBALS['resource_cache_saves'], 'path'),
    'safe_contents' => file_get_contents($install . '/safe.php'),
    'config_contents' => file_get_contents($install . '/include/config.php'),
    'outside_contents' => file_get_contents($outside . '/outside.rrd'),
    'logs' => $GLOBALS['resource_cache_logs'],
), JSON_THROW_ON_ERROR);
PHP;

    file_put_contents($directory . '/probe.php', $program);
    $coverageDirectory = $coverage === null ? '' : $directory;
    $command = array(
        PHP_BINARY,
        '-d', 'error_reporting=24575',
        '-d', 'pcov.directory=' . $root,
        '-d', 'pcov.exclude=~/(include/vendor|tests)/~',
        $directory . '/probe.php',
        $root,
        $install,
        $outside,
        $phpPath,
        $coverage === null ? 'plain' : 'coverage',
        $coverageDirectory,
    );

    try {
        $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $directory);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start resource-cache probe');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        if ($status !== 0 || $stderr !== '') {
            throw new RuntimeException($stderr . $stdout);
        }

        if ($coverage !== null) {
            foreach (glob($directory . '/*.coverage') as $report) {
                $coverage->merge(unserialize(file_get_contents($report)));
            }
        }

        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        $remove = static function ($path) use (&$remove) {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                foreach (array_diff(scandir($path), array('.', '..')) as $entry) {
                    $remove($path . DIRECTORY_SEPARATOR . $entry);
                }
                rmdir($path);
            }
        };
        $remove($directory);
    }
}

test('resource-cache replication confines writes and keeps PHP validation arguments separate', function () {
    $result = runResourceCacheReplicationProbe($this->getTestResultObject()->getCodeCoverage());

    expect($result['destinations'][0])->toBeString();
    expect(array_slice($result['destinations'], 1))->toBe(array(false, false, false, false));
    expect($result['saved_paths'])->toContain('source.txt');
    expect($result['safe_contents'])->toBe('<?php echo "valid";');
    expect($result['config_contents'])->toBe('local database secret');
    expect($result['outside_contents'])->toBe('outside data');
    expect(implode("\n", $result['logs']))->toContain('Refusing unsafe resource cache path');
});
