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
    mkdir($install . '/plugins/x', 0700, true);
    mkdir($install . '/plugins/thold', 0700, true);
    file_put_contents($install . '/include/config.php', 'local database secret');
    file_put_contents($install . '/source.txt', 'safe resource');
    file_put_contents($outside . '/outside.rrd', 'outside data');
    symlink($outside . '/outside.rrd', $install . '/linked.rrd');
    symlink($install . '/source.txt', $install . '/source-link.txt');
    symlink($outside, $install . '/plugins/external');
    file_put_contents($install . '/plugins/x/setup.php', '<?php echo "nested source";');

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
    array('id' => 5, 'path' => 'invalid.php', 'md5sum' => 'cached', 'attributes' => 33188),
    array('id' => 6, 'path' => 'plugins/newplug/lib/a.php', 'md5sum' => 'cached', 'attributes' => 33188),
    array('id' => 7, 'path' => 'plugins/external/new/a.php', 'md5sum' => 'cached', 'attributes' => 33188),
    array('id' => 8, 'path' => 'source-link.txt', 'md5sum' => 'cached', 'attributes' => 33188),
    array('id' => 9, 'path' => 'plugins/thold/config.php', 'md5sum' => 'cached', 'attributes' => 33188),
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
        return base64_encode($parameters[0] === 5 ? '<?php echo ;' : '<?php echo "valid";');
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
    poller_resource_cache_destination('include/CONFIG.php', $installPath, $configPath),
    poller_resource_cache_destination('include/Config.php', $installPath, false),
);

update_db_from_path($outside . '/outside.rrd', 'test', false);
update_db_from_path($install . '/source.txt', 'test', false);
update_db_from_path($install . '/source-link.txt', 'test', false);
update_db_from_path($install . '/linked.rrd', 'test', false);
update_db_from_path($install . '/plugins/external/outside.rrd', 'test', false);
update_db_from_path($install . '/plugins/x/setup.php', 'test', false);
ob_start();
resource_cache_out('test', array('path' => $install, 'recursive' => true));
$lintOutput = ob_get_clean();
$GLOBALS['phpPath'] = '';
$GLOBALS['resource_cache_entries'] = array(array('id' => 10, 'path' => 'no-binary.php', 'md5sum' => 'cached', 'attributes' => 33188));
ob_start();
resource_cache_out('test', array('path' => $install, 'recursive' => true));
ob_end_clean();

echo json_encode(array(
    'lint_output' => $lintOutput,
    'destinations' => $destinations,
    'saved_paths' => array_column($GLOBALS['resource_cache_saves'], 'path'),
    'safe_contents' => file_get_contents($install . '/safe.php'),
    'config_contents' => file_get_contents($install . '/include/config.php'),
    'outside_contents' => file_get_contents($outside . '/outside.rrd'),
    'source_contents' => file_get_contents($install . '/source.txt'),
    'nested_contents' => file_get_contents($install . '/plugins/newplug/lib/a.php'),
    'plugin_config_contents' => file_get_contents($install . '/plugins/thold/config.php'),
    'invalid_written' => file_exists($install . '/invalid.php'),
    'no_binary_written' => file_exists($install . '/no-binary.php'),
    'outside_written' => file_exists($outside . '/new/a.php'),
    'cwd_written' => file_exists(getcwd() . '/plugins/newplug/lib/a.php'),
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
    expect(array_slice($result['destinations'], 1))->toBe(array(false, false, false, false, false, false));
    expect($result['saved_paths'])->toBe(array('source.txt', 'source-link.txt', 'linked.rrd', 'plugins/external/outside.rrd', 'plugins/x/setup.php'));
    expect($result['safe_contents'])->toBe('<?php echo "valid";');
    expect(substr_count($result['lint_output'], 'No syntax errors detected'))->toBe(3);
    expect($result['config_contents'])->toBe('local database secret');
    expect($result['outside_contents'])->toBe('outside data');
    expect($result['source_contents'])->toBe('safe resource');
    expect($result['nested_contents'])->toBe('<?php echo "valid";');
    expect($result['plugin_config_contents'])->toBe('<?php echo "valid";');
    foreach (array('invalid_written', 'no_binary_written', 'outside_written', 'cwd_written') as $key) {
        expect($result[$key])->toBeFalse();
    }
    expect(implode("\n", $result['logs']))->toContain('Refusing unsafe resource cache path');
    expect(implode("\n", $result['logs']))->toContain('has an error while checking syntax (255)')->toContain('has an error while checking syntax (-1)');
});

test('resource replication path grammar preserves legitimate plugin paths', function ($path, $ignored) {
    require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';
    if (!function_exists('Kadupul\\ResourceCacheProbe\\should_ignore_from_replication')) {
        $source = file_get_contents(dirname(__DIR__, 3) . '/lib/poller.php');
        expect($source)->toBeString();
        eval('namespace Kadupul\\ResourceCacheProbe;' . test_php_function_source($source, 'should_ignore_from_replication'));
    }
    expect(\Kadupul\ResourceCacheProbe\should_ignore_from_replication($path))->toBe($ignored);
})->with(array(
    array('a/../b', true), array('/etc/passwd', true), array('C:x', true),
    array('a\\b', true), array("a\0b", true), array('a//b', true), array('a/', true),
    array('a/./b', true), array('plugins/x/.git/config', true), array('', true), array(null, true),
    array('plugins/thold/config.php', false), array('include/fa/x.css', false), array('.github', false),
    array('include/config.php::$DATA', DIRECTORY_SEPARATOR === '\\'),
));
