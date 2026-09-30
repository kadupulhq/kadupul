<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

final class PluginCompatibilityNativeHarness
{
    public static function run(array $scenario, $coverage = null): array
    {
        $root = dirname(__DIR__, 2);
        $dir = sys_get_temp_dir() . '/plugin-compat-native-' . bin2hex(random_bytes(8));
        foreach (array('cli', 'include', 'lib', 'plugins/fixture') as $path) {
            mkdir($dir . '/' . $path, 0700, true);
        }
        copy($root . '/cli/plugin_manage.php', $dir . '/cli/plugin_manage.php');
        copy($root . '/plugins.php', $dir . '/plugins.php');
        file_put_contents($dir . '/lib/poller.php', '<?php');
        file_put_contents($dir . '/lib/database.php', '<?php');
        if (empty($scenario['missing_info'])) {
            file_put_contents($dir . '/plugins/fixture/INFO', "[info]\nname = fixture\n" . ($scenario['metadata'] ?? 'compat = 1.3.0') . "\n");
        }
        file_put_contents($dir . '/plugins/fixture/setup.php', '<?php file_put_contents(__DIR__."/../../setup-ran", "yes"); function plugin_fixture_version() { return array("longname"=>"Fixture", "author"=>"Fixture", "version"=>"1.0"); } function plugin_fixture_install() {} function plugin_fixture_check_config() { return true; }');
        $mode = $scenario['mode'] ?? 'check';
        $bootstrap = '<?php ';
        if ($coverage !== null) {
            $bootstrap .= 'define("PLUGIN_COMPAT_TEST_COVERAGE", true);';
            $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY", ' . var_export($dir, true) . ');';
            if (in_array($mode, array('cli', 'render', 'web'), true)) {
                $script = $mode === 'cli' ? '/cli/plugin_manage.php' : '/plugins.php';
                $bootstrap .= 'define("RRD_TEST_CLI_COVERAGE_COPY", ' . var_export($dir . $script, true) . ');';
                $bootstrap .= 'define("RRD_TEST_CLI_COVERAGE_SOURCE", ' . var_export($root . $script, true) . ');';
            }
        }
        $bootstrap .= 'require ' . var_export($root . '/tests/Fixtures/plugin-compatibility-native-bootstrap.php', true) . ';';
        foreach (array('auth', 'cli_check') as $name) {
            file_put_contents($dir . '/include/' . $name . '.php', $bootstrap);
        }
        file_put_contents($dir . '/check.php', '<?php require __DIR__."/include/auth.php"; $compat=plugin_is_compatible("fixture"); $info=plugin_load_info_file($config["base_path"]."/plugins/fixture/INFO"); echo json_encode(array("compat"=>$compat,"status"=>$info["status"]??-4));');
        file_put_contents($dir . '/health.php', '<?php echo "ready";');
        $environment = array_merge(getenv(), array('PLUGIN_COMPAT_SCENARIO' => json_encode($scenario, JSON_THROW_ON_ERROR), 'PLUGIN_COMPAT_DIRECTORY' => $dir, 'PLUGIN_COMPAT_ROOT' => $root));
        $process = null;
        try {
            $prefix = array(PHP_BINARY, '-d', 'error_reporting=24575', '-d', 'auto_prepend_file=', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~');
            $headers = array();
            if ($mode === 'web') {
                $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
                $address = stream_socket_get_name($socket, false);
                fclose($socket);
                $process = proc_open(array_merge($prefix, array('-S', $address, '-t', $dir)), array(1 => array('file', $dir . '/server.log', 'a'), 2 => array('file', $dir . '/server.log', 'a')), $pipes, $dir, $environment);
                for ($attempt = 0; $attempt < 100; $attempt++) {
                    if (@file_get_contents('http://' . $address . '/health.php') === 'ready') {
                        break;
                    }
                    usleep(20000);
                }
                $context = stream_context_create(array('http' => array('method' => 'POST', 'content' => '', 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 5)));
                $stdout = file_get_contents('http://' . $address . '/plugins.php?mode=install&id=fixture&header=false', false, $context);
                $headers = $http_response_header;
                $status = 0;
            } else {
                $script = $mode === 'cli' ? '/cli/plugin_manage.php' : ($mode === 'render' ? '/plugins.php' : '/check.php');
                $command = array_merge($prefix, array($dir . $script));
                if ($mode === 'cli') {
                    $command = array_merge($command, array('--install', '--plugin=' . ($scenario['plugin'] ?? 'fixture')));
                }
                $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $dir, $environment);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $status = proc_close($process);
                $process = null;
                if ($stderr !== '') {
                    throw new RuntimeException($stderr);
                }
            }
            $report = json_decode(file_get_contents($dir . '/result.json'), true, 512, JSON_THROW_ON_ERROR);
            if ($coverage !== null) {
                $reports = glob($dir . '/*.coverage');
                if (count($reports) !== 1) {
                    throw new RuntimeException('Native compatibility coverage missing');
                }
                foreach ($reports as $file) {
                    $coverage->merge(unserialize(file_get_contents($file)));
                }
            }
            return array_merge($report, array('status' => $status, 'stdout' => $stdout, 'headers' => $headers));
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($dir);
        }
    }
}
