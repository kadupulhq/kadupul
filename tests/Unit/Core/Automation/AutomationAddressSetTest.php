<?php
declare(strict_types=1);
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/
require_once dirname(__DIR__, 4) . '/lib/api_automation.php';
test('range count agrees with the unique enumerated addresses', function ($input, $count, $first, $last) {
	$range = automation_address_range($input);
	expect($range['count'])->toBe($count);
	expect(automation_address_at($range, 0))->toBe($first);
	expect(automation_address_at($range, $count - 1))->toBe($last);
	$seen = array();
	for ($offset = 0; $offset < $count; $offset++) {
		$ip = automation_address_at($range, $offset);
		expect(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))->not->toBeFalse();
		$seen[$ip] = true;
	}
	expect(count($seen))->toBe($count);
})->with(array(
	array('10.1.*.1',256,'10.1.0.1','10.1.255.1'),
	array('192.0.2.*',254,'192.0.2.1','192.0.2.254'),
	array('192.0.2.0/255.255.255.252',2,'192.0.2.1','192.0.2.2'),
	array('192.0.2.8/31',2,'192.0.2.8','192.0.2.9'),
	array('192.0.2.8/32',1,'192.0.2.8','192.0.2.8'),
	array('192.0.2.8',1,'192.0.2.8','192.0.2.8'),
));
test('malformed ranges are rejected before enumeration', function ($range) {
	expect(automation_address_range($range))->toBeNull();
})->with(array('10.0.0.0/1.5','10.0.0.0/24/2','10.0.0.0/33','10.0.0.0/255.0.255.0','10.*.999.1','10.*.*','::1/64','bad',''));
test('out of range offsets cannot escape the address set', function () {
	$range = automation_address_range('192.0.2.0/30');
	expect(fn() => automation_address_at($range, 2))->toThrow(OutOfRangeException::class);
});

test('network detail preserves subnet metadata for callers', function ($input, $cidr, $network, $broadcast, $type) {
	$detail = automation_get_network_info($input);
	expect($detail['cidr'])->toBe($cidr);
	expect($detail['network'])->toBe($network);
	expect($detail['broadcast'])->toBe($broadcast);
	expect($detail['type'])->toBe($type);
})->with(array(
	array('192.0.2.15/24',24,'192.0.2.0','192.0.2.255','subnet'),
	array('192.0.2.*',24,'192.0.2.0','192.0.2.255','subnet'),
	array('192.0.2.8',32,'192.0.2.8','192.0.2.8','single'),
	array('192.0.2.8/31',31,'192.0.2.8','192.0.2.9','subnet'),
	array('10.1.*.1',false,'10.1.0.1','10.1.255.1','range'),
));
