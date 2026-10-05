<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace HostDiskNativeTest;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';
$root = dirname(__DIR__, 2);
foreach (['REGEXP_SNMP_TRIM' => 'lib/snmp.php', 'SNMP_STRING_OUTPUT_GUESS' => 'lib/snmp.php', 'SNMP_POLLER' => 'include/global_constants.php'] as $constant => $file) {
    $constantSource = file_get_contents($root . '/' . $file);
    if ($constantSource === false || !preg_match("/^\\s*define\\('" . preg_quote($constant, '/') . "',\\s*([^\\n]+)\\);/m", $constantSource, $definition)) {
        throw new \RuntimeException('Missing production constant ' . $constant);
    }
    eval('namespace ' . __NAMESPACE__ . '; const ' . $constant . ' = ' . $definition[1] . ';');
}
foreach ([['lib/functions.php', 'cacti_sizeof'], ['lib/functions.php', 'is_hex_string'],
    ['lib/snmp.php', 'format_snmp_string'], ['scripts/ss_host_disk.php', 'ss_host_disk']] as [$file, $name]) {
    $source = file_get_contents($root . '/' . $file);
    if ($source === false) {
        throw new \RuntimeException('Unable to read ' . $file);
    }
    eval('namespace ' . __NAMESPACE__ . ';' . test_php_function_source($source, $name));
}
$snmpSource = file_get_contents($root . '/lib/snmp.php');
if ($snmpSource === false || !preg_match('/^\$banned_snmp_strings = (array\([^\n]+\));/m', $snmpSource, $bannedDefinition)) {
    throw new \RuntimeException('Missing production SNMP sentinel strings');
}
eval('$diskBannedStrings = ' . $bannedDefinition[1] . ';');

function api_plugin_hook_function($name, $input)
{
    return $GLOBALS['disk_hook'] ?? $input;
}
function cacti_snmp_get(...$arguments)
{
    $GLOBALS['disk_get_calls'][] = $arguments;
    return $GLOBALS['disk_sample'];
}
function db_fetch_cell_prepared($query, $parameters)
{
    $statement = $GLOBALS['disk_db']->prepare($query);
    $statement->execute($parameters);
    return $statement->fetchColumn();
}

beforeEach(function () use ($diskBannedStrings) {
    $this->diskHadBannedStrings = array_key_exists('banned_snmp_strings', $GLOBALS);
    $this->diskPreviousBannedStrings = $GLOBALS['banned_snmp_strings'] ?? null;
    $GLOBALS['banned_snmp_strings'] = $diskBannedStrings;
    $GLOBALS['disk_hook'] = null;
    $GLOBALS['disk_get_calls'] = [];
    $GLOBALS['disk_db'] = new \PDO('sqlite::memory:');
    $GLOBALS['disk_db']->exec('CREATE TABLE host_snmp_cache (host_id INTEGER, field_name VARCHAR(50), snmp_index VARCHAR(50), field_value VARCHAR(255))');
});

afterEach(function () {
    if ($this->diskHadBannedStrings) {
        $GLOBALS['banned_snmp_strings'] = $this->diskPreviousBannedStrings;
    } else {
        unset($GLOBALS['banned_snmp_strings']);
    }
});

test('native disk getter consumes cached allocation units and nonnegative Integer32 samples', function ($sample, $units, $expected, $argument) {
    $GLOBALS['disk_sample'] = $sample;
    if ($units !== null) {
        $cached = format_snmp_string($units, false);
        $GLOBALS['disk_db']->prepare('INSERT INTO host_snmp_cache VALUES (?, ?, ?, ?)')->execute([7, 'hrStorageAllocationUnits', '1', $cached]);
    }
    $actual = ss_host_disk('192.0.2.7', 7, '2:161:500:1:10:public', 'get', $argument, '1');
    expect($actual)->toBe($expected)->and($GLOBALS['disk_get_calls'])->toHaveCount(1);
    expect($GLOBALS['disk_get_calls'][0][2])->toBe($argument === 'total' ? '.1.3.6.1.2.1.25.2.3.1.5.1' : '.1.3.6.1.2.1.25.2.3.1.6.1');
})->with([
    ['42', 'INTEGER: 4096 Bytes', 172032],
    ['42', '4096 bytes', 172032],
    ['42', '4096', 172032],
    ['42', null, 'U'],
    ['42', '0', 'U'],
    ['42', '-4096', 'U'],
    ['42', '4096x', 'U'],
    ['0', '4096 Bytes', 0],
    ['-96', '4096 Bytes', 'U'],
    ['-2147483648', '4096', 'U'],
    ['2147483647', '4096', 8796093018112],
    ['-2147483649', '4096', 'U'],
    ['-1.5', '4096', 'U'],
    ['2147483648', '4096', 'U'],
    ['42', '2147483648 Bytes', 'U'],
    ['1.5', '4096', 'U'],
    ['1e3', '4096', 'U'],
    ['42', '4096.0 Bytes', 'U'],
    ['42', '1e3 Bytes', 'U'],
    ['unknown', '4096', 'U'],
    ['1e999', '4096', 'U'],
    [INF, '4096', 'U'],
    [NAN, '4096', 'U'],
])->with(['total', 'used']);

test('native disk getter retains plugin-owned results without SNMP or cache access', function () {
    $GLOBALS['disk_hook'] = 'plugin-owned';
    expect(ss_host_disk('192.0.2.7', 7, '2:161:500:1:10:public', 'get', 'used', '1'))->toBe('plugin-owned')
        ->and($GLOBALS['disk_get_calls'])->toBe([]);
});
