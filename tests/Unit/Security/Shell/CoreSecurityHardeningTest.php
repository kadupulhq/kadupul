<?php
require_once dirname(__DIR__, 3) . "/Helpers/PhpSource.php";
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

$pingSource = file_get_contents(__DIR__ . '/../../../../lib/ping.php');
$snmpSource = file_get_contents(__DIR__ . '/../../../../lib/snmp.php');
$dqSource   = file_get_contents(__DIR__ . '/../../../../lib/data_query.php');
$impSource  = file_get_contents(__DIR__ . '/../../../../lib/import.php');

test('ping constructor casts retries to int', function () use ($pingSource) {
	expect($pingSource)->toContain('$retries = (int)$retries');
});

test('ping constructor casts timeout to int', function () use ($pingSource) {
	expect($pingSource)->toContain('$timeout = (int)$timeout');
});

test('snmp_escape_string always wraps Windows strings', function () use ($snmpSource) {
	$body = test_php_function_source($snmpSource, 'snmp_escape_string');
	expect($body)->not->toContain('if (substr_count($string, SNMP_ESCAPE_CHARACTER))');
});

test('get_script_query_path uses cacti_escapeshellcmd on script path', function () use ($dqSource) {
	$body = test_php_function_source($dqSource, 'get_script_query_path');
	expect($body)->toContain('cacti_escapeshellcmd($parsed_script_path)');
});

test('get_script_query_path rejects path traversal', function () use ($dqSource) {
	$body = test_php_function_source($dqSource, 'get_script_query_path');
	expect(str_contains($body, "strpos(\$parsed_script_path, '..')"))->toBeTrue();
});

test('data_query value_index_parse constrains PCRE backtrack limit', function () use ($dqSource) {
	expect($dqSource)->toContain("ini_set('pcre.backtrack_limit', '10000')");
	expect($dqSource)->toContain("ini_set('pcre.recursion_limit', '10000')");
});

test('data_query value_index_parse restores PCRE limits', function () use ($dqSource) {
	expect($dqSource)->toContain("ini_set('pcre.backtrack_limit', \$old_backtrack)");
	expect($dqSource)->toContain("ini_set('pcre.recursion_limit', \$old_recursion)");
});

test('data_query_duplicate uses strip_tags on name', function () use ($dqSource) {
	$body = test_php_function_source($dqSource, 'data_query_duplicate');
	expect($body)->toContain('strip_tags($data_query_name)');
});

test('import.php never enables entity expansion when parsing package XML', function () use ($impSource) {
	expect($impSource)->not->toContain('LIBXML_NOENT');
	expect($impSource)->not->toContain('LIBXML_DTDLOAD');
	expect($impSource)->not->toContain('libxml_disable_entity_loader(');
});

test('import.php suppresses libxml warnings', function () use ($impSource) {
	expect($impSource)->toContain('libxml_use_internal_errors(true)');
});
