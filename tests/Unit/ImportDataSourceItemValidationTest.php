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
*/

/*
 * import_validate_data_source_item() applies the Data Source form rules to
 * template XML values before they reach an RRDtool command line.
 */

if (!function_exists('import_validate_data_source_item')) {
	$source = file_get_contents(dirname(__DIR__, 2) . '/lib/import.php');
	preg_match('/^function import_validate_data_source_item\(.*?^}\n/ms', $source, $match);
	eval($match[0]);
}

test('accepts the values the Data Source form accepts', function () {
	expect(import_validate_data_source_item('data_source_name', 'traffic_in'))->toBeTrue();
	expect(import_validate_data_source_item('rrd_minimum', '0'))->toBeTrue();
	expect(import_validate_data_source_item('rrd_maximum', 'U'))->toBeTrue();
	expect(import_validate_data_source_item('rrd_maximum', '1.5e10'))->toBeTrue();
	expect(import_validate_data_source_item('rrd_maximum', '|query_ifSpeed|'))->toBeTrue();
	expect(import_validate_data_source_item('data_source_type_id', 'anything'))->toBeTrue();
});

test('rejects values that could alter an RRDtool command line', function () {
	expect(import_validate_data_source_item('data_source_name', 'name with space'))->toBeFalse();
	expect(import_validate_data_source_item('data_source_name', str_repeat('a', 20)))->toBeFalse();
	expect(import_validate_data_source_item('rrd_maximum', "100\nupdate x"))->toBeFalse();
	expect(import_validate_data_source_item('rrd_minimum', '0; rm'))->toBeFalse();
	expect(import_validate_data_source_item('rrd_maximum', '|query_ifDescr|'))->toBeFalse();
});
