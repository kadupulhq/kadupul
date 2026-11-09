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
$GLOBALS['config'] = array('is_web' => true);
function automation_test_monthly(array $overrides = array()): array {
	return array_replace(array('sched_type'=>'4','month'=>'11,12','day_of_month'=>'8','start_at'=>'2026-01-08 09:00:00','next_start'=>'2026-11-08 09:00:00'), $overrides);
}
beforeEach(function () { $GLOBALS['automation_previous_timezone'] = date_default_timezone_get(); date_default_timezone_set('UTC'); });
afterEach(function () { date_default_timezone_set($GLOBALS['automation_previous_timezone']); });
test('a monthly due check does not consume an exactly due occurrence', function () {
	$network = automation_test_monthly(); $due = strtotime('2026-11-08 09:00:00');
	expect(automation_due_time($network))->toBe($due);
	expect(automation_due_time($network))->toBe($due);
	expect(automation_next_schedule($network, $due))->toBe(strtotime('2026-12-08 09:00:00'));
});
test('monthly initialization honors the complete start date', function () {
	$network = automation_test_monthly(array('start_at'=>'2026-12-08 09:00:00','next_start'=>'0000-00-00 00:00:00'));
	expect(automation_due_time($network))->toBe(strtotime('2026-12-08 09:00:00'));
});
test('invalid calendar dates are skipped until the next leap day', function () {
	$network = automation_test_monthly(array('month'=>'2','day_of_month'=>'29','start_at'=>'2024-02-29 09:00:00'));
	expect(automation_next_schedule($network, strtotime('2025-01-01')))->toBe(strtotime('2028-02-29 09:00:00'));
});
test('last day and fourth weekday retain calendar semantics', function () {
	$network = automation_test_monthly(array('month'=>'2','day_of_month'=>'32'));
	expect(automation_next_schedule($network, strtotime('2026-02-01')))->toBe(strtotime('2026-02-28 09:00:00'));
	$network = automation_test_monthly(array('sched_type'=>'5','month'=>'1','monthly_week'=>'4','monthly_day'=>'1'));
	expect(automation_next_schedule($network, strtotime('2026-12-01')))->toBe(strtotime('2027-01-24 09:00:00'));
});
test('daily recurrence preserves wall time through daylight saving changes', function ($after, $expected) {
	date_default_timezone_set('America/Los_Angeles');
	$network = array('sched_type'=>'2','recur_every'=>'1','start_at'=>'2026-01-01 09:00:00');
	expect(date('Y-m-d H:i:s', automation_next_schedule($network, strtotime($after))))->toBe($expected);
})->with(array(array('2026-03-07 09:01:00','2026-03-08 09:00:00'),array('2026-10-31 09:01:00','2026-11-01 09:00:00')));
test('weekly catchup is future and honors alternate weeks', function () {
	$network = array('sched_type'=>'3','recur_every'=>'2','start_at'=>'2026-01-05 09:00:00','day_of_week'=>'2,4');
	expect(date('Y-m-d H:i:s', automation_next_schedule($network, strtotime('2026-01-07 09:00:00'))))->toBe('2026-01-19 09:00:00');
	expect(automation_next_schedule($network, strtotime('2026-11-08')))->toBeGreaterThan(strtotime('2026-11-08'));
});
test('invalid recurrence terminates with an error', function ($interval) {
	$network = array('sched_type'=>'2','recur_every'=>$interval,'start_at'=>'2026-01-01 09:00:00');
	expect(fn() => automation_next_schedule($network, strtotime('2026-11-01')))->toThrow(RuntimeException::class);
})->with(array('0','-1','1.5','bad','366'));
