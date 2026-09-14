<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * color.php, host.php and lib/auth.php now pass $escape explicitly to
 * str_getcsv()/fputcsv() instead of relying on PHP's
 * default, which PHP 8.4 deprecates omitting. The values passed, ',', '"'
 * and '\\', are exactly PHP's own pre-8.4 defaults, so parsing and output
 * for a sample containing quotes and backslashes must match what the
 * implicit call always produced.
 */

test('str_getcsv with explicit defaults parses a quoted, backslash-bearing field', function () {
	$line = "\"CORP\\jdoe\",\"quoted \"\"value\"\",here\",plain\n";

	expect(str_getcsv($line, ',', '"', '\\'))
		->toBe(array('CORP\jdoe', 'quoted "value",here', 'plain'));
});

test('fputcsv with explicit defaults writes and round-trips a quoted, backslash-bearing field', function () {
	$fields = array('CORP\jdoe', 'quoted "value" here', 'a,field,with,commas');

	$stream = fopen('php://memory', 'r+');
	fputcsv($stream, $fields, ',', '"', '\\');
	rewind($stream);
	$output = stream_get_contents($stream);
	fclose($stream);

	expect($output)->toBe("\"CORP\\jdoe\",\"quoted \"\"value\"\" here\",\"a,field,with,commas\"\n");

	$roundTrip = str_getcsv(rtrim($output, "\n"), ',', '"', '\\');
	expect($roundTrip)->toBe($fields);
});
