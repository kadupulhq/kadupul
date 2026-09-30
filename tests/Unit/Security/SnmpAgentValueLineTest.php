<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

// lib/mib_cache.php runs in a child process so its database calls can be
// captured without a database and without clashing with other test stubs.
function runMibCacheValueProbe($coverage): array
{
    $root      = dirname(__DIR__, 3);
    $directory = sys_get_temp_dir() . '/mib-cache-value-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);

    $program = <<<'PHP'
<?php
$root = $argv[1];
if ($argv[2] === 'coverage') {
    define('MIB_CACHE_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $argv[3]);
    require $root . '/tests/Fixtures/rrd-process-coverage.php';
}
$GLOBALS['row_exists'] = false;
$GLOBALS['written'] = array();
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function db_qstr($value) { return "'" . addslashes((string) $value) . "'"; }
function db_fetch_cell_prepared($sql, $params = array()) {
    return strpos($sql, 'SEQUENCE OF') !== false ? '1.3.6.1.4.1.23925.1.2' : $GLOBALS['row_exists'];
}
function db_fetch_assoc_prepared($sql, $params = array()) {
    return array(array('oid' => '1.3.6.1.4.1.23925.1.2.1.1', 'name' => 'descr', 'mib' => 'CACTI-MIB',
        'type' => 'DisplayString', 'otype' => 'Column', 'max-access' => 'read-only', 'value' => ''));
}
function db_execute_prepared($sql, $params = array()) { $GLOBALS['written'][] = $params; return true; }
function db_execute($sql) { $GLOBALS['written'][] = $sql; return true; }
require $root . '/lib/mib_cache.php';

$mc = new MibCache('CACTI-MIB');
$mc->table('cactiTestTable')->row(1)->insert(array('descr' => "insert\r\nvalue"));
$GLOBALS['row_exists'] = 1;
$mc->table('cactiTestTable')->row(1)->update(array('descr' => "update\nvalue"));
$mc->object('cactiTestObject')->set("set\rvalue");
$mc->object('cactiTestObject')->set(42);
echo json_encode($GLOBALS['written'], JSON_THROW_ON_ERROR);
PHP;

    file_put_contents($directory . '/probe.php', $program);
    $command = array(
        PHP_BINARY,
        '-d', 'error_reporting=24575',
        '-d', 'pcov.directory=' . $root,
        '-d', 'pcov.exclude=~/(include/vendor|tests)/~',
        $directory . '/probe.php',
        $root,
        $coverage === null ? 'plain' : 'coverage',
        $directory,
    );

    try {
        $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $directory);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start MIB cache probe');
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
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}

test('MIB cache writes keep each value on one line', function () {
    $written = runMibCacheValueProbe($this->getTestResultObject()->getCodeCoverage());

    expect($written)->toHaveCount(4)
        ->and($written[0][7])->toBe('insert  value')
        ->and($written[1])->toContain("'update value'")
        ->and($written[2][0])->toBe('set value')
        ->and($written[3][0])->toBe(42);
});

test('pass_persist output removes line breaks from cached values', function () {
    $source = file_get_contents(dirname(__DIR__, 3) . '/snmpagent_persist.php');
    eval(test_php_function_source($source, 'snmpagent_persist_safe_value'));

    expect(snmpagent_persist_safe_value("a\r\nb\nc\rd"))->toBe('a  b c d')
        ->and(snmpagent_persist_safe_value(7))->toBe('7')
        ->and(substr_count($source, 'snmpagent_persist_safe_value($data[\'value\'])'))->toBe(2);
});
