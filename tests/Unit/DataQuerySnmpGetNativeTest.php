<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\DataQuerySnmpGetNative;

require_once dirname(__DIR__) . '/Helpers/ChildProcessCoverage.php';

function runQuery(array $scenario): array
{
    $root = dirname(__DIR__, 2);
    $owned = sys_get_temp_dir() . '/query-snmp-native-' . bin2hex(random_bytes(8));
    if (!mkdir($owned, 0700)) throw new \RuntimeException('Cannot create owned SNMP fixture directory');
    $program = <<<'CHILD'
        $root = $argv[1];
        require $root . '/tests/Fixtures/data-query-snmp-get-native.php';
        $scenario = json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR);
        $result = native_query_snmp_get($root, $argv[2], $scenario);
        if (!empty($scenario['probeReturns'])) {
            $result['helperReturns'] = array();
            foreach (array(null, false, 0, '', "\0binary") as $value) {
                $GLOBALS['queryReturnOverride'] = $value;
                foreach (array(array(), array('output_format' => 'hex')) as $field) {
                    $returned = data_query_snmp_get_field($result['host'], $field, $GLOBALS['querySession'], '.1.3.6.1.3.7');
                    if ($returned !== $value) throw new RuntimeException('Shared field helper changed the transport return value');
                    $result['helperReturns'][] = $returned;
                }
            }
            unset($GLOBALS['queryReturnOverride']);
            $GLOBALS['nativeChildCoverageMarkers'][] = 'native-helper-values-preserved';
        }
        print json_encode($result, JSON_THROW_ON_ERROR);
        CHILD;
    $registration = \child_coverage_registration(
        __FILE__,
        empty($scenario['probeReturns']) ? 'whole-snmp-query-get' : 'snmp-query-get-return-values',
        $scenario,
        array_merge(array('actual-snmp-query-completed', 'actual-snmp-cache-readback', 'actual-session-close'), empty($scenario['probeReturns']) ? array() : array('native-helper-values-preserved')),
        array('lib/data_query.php', 'lib/xml.php'),
        array('tests/Fixtures/data-query-snmp-get-native.php', 'lib/data_query.php', 'lib/xml.php', 'include/global_constants.php', 'cacti.sql', 'lib/snmp.php', 'tests/Helpers/PhpSource.php')
    );
    $registration['collectorPrelude'] = 'define("SNMP_QUERY_NATIVE_TEST_COVERAGE", true);';
    $command = \child_coverage_command(array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program,
        $root, $owned, json_encode($scenario, JSON_THROW_ON_ERROR)), $coverageDirectory, $registration);
    try {
        $process = proc_open($command, array(0 => array('file', '/dev/null', 'r'), 1 => array('file', $owned . '/stdout', 'w'), 2 => array('file', $owned . '/stderr', 'w')), $pipes);
        if (!is_resource($process)) throw new \RuntimeException('Cannot start native SNMP query');
        $status = proc_close($process);
        $errors = file_get_contents($owned . '/stderr');
        expect($errors)->toBe('')->and($status)->toBe(0);
        \child_coverage_collect($coverageDirectory);
        return json_decode(file_get_contents($owned . '/stdout'), true, 512, JSON_THROW_ON_ERROR);
    } finally {
        foreach (array_filter(array($owned, $coverageDirectory)) as $directory) {
            if (!is_dir($directory)) continue;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($directory);
            unset($GLOBALS['child_coverage_registrations'][$directory]);
        }
    }
}

dataset('native SNMP field formats', array(
    'omitted uses session' => array(array('suffix' => false, 'rewrite' => false)),
    'null uses session' => array(array('format' => null, 'suffix' => false, 'rewrite' => false)),
    'hex uses explicit get' => array(array('format' => 'hex', 'suffix' => false, 'rewrite' => false)),
    'ascii uses explicit get' => array(array('format' => 'ascii', 'suffix' => false, 'rewrite' => false)),
    'empty uses guess' => array(array('format' => '', 'suffix' => false, 'rewrite' => false)),
    'unknown uses guess' => array(array('format' => 'unknown', 'suffix' => false, 'rewrite' => false)),
    'zero uses guess' => array(array('format' => '0', 'suffix' => false, 'rewrite' => false)),
    'whitespace uses guess' => array(array('format' => ' hex ', 'suffix' => false, 'rewrite' => false)),
    'uppercase uses guess' => array(array('format' => 'HEX', 'suffix' => false, 'rewrite' => false)),
    'suffix retained on session path' => array(array('suffix' => true, 'rewrite' => false)),
    'suffix retained on formatted path' => array(array('format' => 'hex', 'suffix' => true, 'rewrite' => false)),
    'existing extension constants are preserved' => array(array('format' => 'hex', 'suffix' => false, 'rewrite' => false, 'predefined' => true)),
    'plain field rewrites its OID' => array(array('format' => 'ascii', 'suffix' => true, 'rewrite' => true)),
));

test('whole SNMP query preserves formatted and regexp field transport tuples and persisted cache identity', function (array $scenario): void {
    $result = runQuery($scenario);
    expect($result['rows'])->toHaveCount(7)->and($result['transport'])->toHaveCount(6)->and($result['sql'])->toHaveCount(3);
    expect($result['rows'][6])->toBe(array('host_id' => 5, 'snmp_query_id' => 10, 'field_name' => 'adjacent', 'field_value' => 'untouched', 'snmp_index' => '7', 'oid' => 'adjacent-oid', 'present' => 1));
    expect($result['transport'][0])->toBe(array('session', array('192.0.2.7', 'fictional-community', 3, 'fictional-user', 'fictional-auth', 'SHA', 'fictional-privacy', 'AES', 'fixture-context', 'fixture-engine', 1161, 1501, 2, 10, 5)));
    $format = $scenario['format'] ?? null;
    $constants = $result['constants'];
    foreach ($result['preexisting_constants'] as $name => $value) expect($constants[$name])->toBe($value);
    foreach (array('value', 'capture') as $fieldNumber => $field) {
        foreach (array(7, 9) as $position => $index) {
            $oid = '.1.3.6.1.' . ($field === 'capture' ? '4' : ($scenario['rewrite'] ? '30' : '3')) . '.' . $index . ($scenario['suffix'] ? '.5' : '');
            $call = $result['transport'][2 + $fieldNumber * 2 + $position];
            if ($format === null) {
                expect($call)->toBe(array('session-get', $oid));
            } else {
                expect($call)->toBe(array('get', array('192.0.2.7', 'fictional-community', $oid, 3, 'fictional-user', 'fictional-auth', 'SHA', 'fictional-privacy', 'AES', 'fixture-context', 1161, 1501, $constants['SNMP_POLLER'], 'fixture-engine', $format === 'hex' ? $constants['SNMP_STRING_OUTPUT_HEX'] : ($format === 'ascii' ? $constants['SNMP_STRING_OUTPUT_ASCII'] : $constants['SNMP_STRING_OUTPUT_GUESS']))));
            }
            $row = array_values(array_filter($result['rows'], static fn(array $row): bool => $row['host_id'] === 4 && $row['field_name'] === $field && $row['snmp_index'] === (string) $index));
            expect($row)->toBe(array(array('host_id' => 4, 'snmp_query_id' => 10, 'field_name' => $field,
                'field_value' => ($field === 'value' ? 'sample-' : '') . ($scenario['suffix'] ? '5' : (string) $index), 'snmp_index' => (string) $index, 'oid' => $oid, 'present' => 1)));
        }
    }
})->with('native SNMP field formats');


test('omitting the actual SNMP query refuses completion evidence', function (): void {
    $root = dirname(__DIR__, 2);
    $source = file_get_contents($root . '/tests/Fixtures/data-query-snmp-get-native.php');
    expect($source)->not->toBeFalse();
    $altered = str_replace('$result = query_snmp_host(4, 10);', '$result = false;', $source, $changed);
    expect($changed)->toBe(1);
    $directory = sys_get_temp_dir() . '/query-snmp-omission-' . bin2hex(random_bytes(8));
    expect(mkdir($directory, 0700))->toBeTrue();
    try {
        expect(file_put_contents($directory . '/producer.php', $altered))->toBe(strlen($altered));
        $program = 'require $argv[1]; native_query_snmp_get($argv[2],$argv[3],array("format"=>"hex","suffix"=>false,"rewrite"=>false));';
        $process = proc_open(
            array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, $directory . '/producer.php', $root, $directory),
            array(0 => array('file', '/dev/null', 'r'), 1 => array('file', $directory . '/stdout', 'w'), 2 => array('file', $directory . '/stderr', 'w')),
            $pipes
        );
        expect($process)->toBeResource();
        $status = proc_close($process);
        expect($status)->not->toBe(0)->and(file_get_contents($directory . '/stderr'))->toContain('Query did not complete and close its admitted session');
        expect(file_get_contents($directory . '/stdout'))->toBe('');
    } finally {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
});


test('shared SNMP field helper preserves null false zero empty and binary transport results', function (): void {
    $result = runQuery(array('format' => 'hex', 'suffix' => false, 'rewrite' => false, 'probeReturns' => true));
    expect($result['helperReturns'])->toBe(array(null, null, false, false, 0, 0, '', '', "\0binary", "\0binary"));
});
