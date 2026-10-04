<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 */

/* Host Resources storage counters are defined as nonnegative values. */
$source = file_get_contents(__DIR__ . '/../../../scripts/ss_host_disk.php');

if ($source === false) {
	throw new RuntimeException('Unable to read scripts/ss_host_disk.php');
}

preg_match('/if \(!is_numeric\(\$snmp_data\).*?return \$snmp_data \* \$sau;/s', $source, $matches);

test('invalid disk counters and allocation units are rejected by the source', function () use ($matches) {
	expect($matches)->not->toBeEmpty();
	expect($matches[0])->toContain("return 'U';")->toContain('ctype_digit((string) $sau)');
});

/**
 * Run the real disk-size validation chain extracted from ss_host_disk().
 *
 * @param mixed $snmp_data Raw hrStorageSize/hrStorageUsed SNMP value
 * @param mixed $sau Allocation unit
 *
 * @return mixed Disk size in bytes, or 'U' for invalid data
 */
$compute = function (mixed $snmp_data, mixed $sau) use ($matches) {
	$name = 'ss_host_disk_get_' . str_replace('.', '_', uniqid('', true));
	eval("function $name(\$snmp_data, \$sau) { {$matches[0]} }");

	return $name($snmp_data, $sau);
};

test('negative disk counters remain unknown instead of being guessed as wrapped values', function () use ($compute) {
	expect($compute(-96, 1))->toBe('U');
});

test('invalid allocation units return U', function () use ($compute) {
	foreach (array('', null, '0', 0, -1, '1024x') as $sau) {
		expect($compute(96, $sau))->toBe('U');
	}
});

test('non-negative values, including zero, multiply by valid allocation units', function () use ($compute) {
	expect($compute(500, 4096))->toEqual(500 * 4096)
		->and($compute(0, 4096))->toEqual(0);
});
