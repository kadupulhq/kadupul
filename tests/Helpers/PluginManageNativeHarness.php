<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

final class PluginManageNativeHarness
{
    public static function run(array $scenario, $coverage = null): array
    {
        $root = dirname(__DIR__, 2);
        $dir = sys_get_temp_dir() . '/plugin-native-' . bin2hex(random_bytes(8));
        mkdir($dir . '/cli', 0700, true);
        mkdir($dir . '/include', 0700);
        foreach (array('fixture', 'second') as $plugin) {
            mkdir($dir . '/plugins/' . $plugin, 0700, true);
        }
        copy($root . '/cli/plugin_manage.php', $dir . '/cli/plugin_manage.php');
        $bootstrap = '<?php ';
        if ($coverage !== null) {
            foreach (array('RRD_TEST_COVERAGE_DIRECTORY' => $dir, 'RRD_TEST_CLI_COVERAGE_COPY' => $dir . '/cli/plugin_manage.php', 'RRD_TEST_CLI_COVERAGE_SOURCE' => $root . '/cli/plugin_manage.php') as $name => $value) {
                $bootstrap .= 'define(' . var_export($name, true) . ',' . var_export($value, true) . ');';
            }
            $bootstrap .= 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
        }
        $bootstrap .= 'require ' . var_export($root . '/tests/Fixtures/plugin-manage-native-bootstrap.php', true) . ';';
        file_put_contents($dir . '/include/cli_check.php', $bootstrap);
        try {
            $command = array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $dir . '/cli/plugin_manage.php', '--install', '--allperms');
            foreach ($scenario['plugins'] ?? array('fixture') as $plugin) {
                $command[] = '--plugin=' . $plugin;
            }
            $process = proc_open(
                $command,
                array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
                $pipes,
                $dir,
                array_merge(getenv(), array('PLUGIN_NATIVE_SCENARIO' => json_encode($scenario, JSON_THROW_ON_ERROR), 'PLUGIN_NATIVE_DIRECTORY' => $dir, 'PLUGIN_NATIVE_ROOT' => $root))
            );
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
            if ($stderr !== '') {
                throw new RuntimeException($stderr);
            }
            list($output, $report) = explode("\nRESULT:", $stdout);
            if ($coverage !== null) {
                $reports = glob($dir . '/*.coverage');
                if (count($reports) !== 1) {
                    throw new RuntimeException('Native CLI coverage report missing');
                }
                $coverage->merge(unserialize(file_get_contents($reports[0])));
            }
            return array_merge(json_decode($report, true, 512, JSON_THROW_ON_ERROR), array('status' => $status, 'stdout' => $output));
        } finally {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($dir);
        }
    }
}
