<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

final class SnmpPersistNativeHarness
{
    public static function run(array $rows, string $commands, $coverage): array
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/snmp-persist-native-' . bin2hex(random_bytes(8));
        mkdir($directory . '/include', 0700, true);
        mkdir($directory . '/cache/mibcache', 0700, true);
        $directory = realpath($directory);
        $database = new PDO('sqlite:' . $directory . '/cache.sqlite');
        $database->exec('CREATE TABLE snmpagent_cache(oid TEXT, type TEXT, otype TEXT, `max-access` TEXT, value TEXT)');
        $insert = $database->prepare('INSERT INTO snmpagent_cache VALUES(?,?,?,?,?)');
        foreach ($rows as $row) {
            $insert->execute(array($row['oid'], $row['type'] ?? 'DisplayString', $row['otype'] ?? 'DATA', $row['access'] ?? 'read-only', $row['value']));
        }
        file_put_contents($directory . '/snmpagent_mibcache.php', '<?php');
        $process = null;
        try {
            foreach (array('snmpagent_mibcachechild.php', 'snmpagent_persist.php') as $source) {
                copy($root . '/' . $source, $directory . '/' . $source);
                $bootstrap = '<?php error_reporting(24575); ';
                if ($coverage !== null) {
                    foreach (array('RRD_TEST_COVERAGE_DIRECTORY' => $directory, 'RRD_TEST_CLI_COVERAGE_COPY' => $directory . '/' . $source, 'RRD_TEST_CLI_COVERAGE_SOURCE' => $root . '/' . $source) as $name => $value) {
                        $bootstrap .= 'define(' . var_export($name, true) . ',' . var_export($value, true) . ');';
                    }
                    $bootstrap .= 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
                }
                $bootstrap .= 'require ' . var_export($root . '/tests/Fixtures/snmp-persist-native-bootstrap.php', true) . ';';
                file_put_contents($directory . '/include/cli_check.php', $bootstrap);
                $process = proc_open(array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'error_reporting=24575', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $directory . '/' . $source), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $directory, array_merge(getenv(), array('SNMP_NATIVE_DIRECTORY' => $directory)));
                if (!is_resource($process)) {
                    throw new RuntimeException('Unable to start native SNMP process');
                }
                fwrite($pipes[0], $source === 'snmpagent_persist.php' ? $commands . "shutdown\n" : '');
                fclose($pipes[0]);
                stream_set_timeout($pipes[1], 10);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $status = proc_close($process);
                $process = null;
                if ($status !== 0 || $stderr !== '') {
                    throw new RuntimeException($stdout . $stderr);
                }
                if ($source === 'snmpagent_mibcachechild.php' && $stdout !== '') {
                    throw new RuntimeException('Unexpected native cache-builder output: ' . $stdout);
                }
            }
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                if (count($reports) !== 2) {
                    throw new RuntimeException('Expected coverage from both native SNMP processes');
                }
                foreach ($reports as $report) {
                    $coverage->merge(unserialize(file_get_contents($report)));
                }
            }
            return array('stdout' => $stdout, 'cache' => file_get_contents($directory . '/cache/mibcache/mibcache.tmp'));
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            $database = null;
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($directory);
        }
    }
}
