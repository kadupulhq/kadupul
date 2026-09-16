<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

test('GHSA-274c-97hj-pv2v: import package flow enforces signature validation', function () {
	$src = file_get_contents(__DIR__ . '/../../../../lib/import.php');

	expect($src)->toContain('if (!import_validate_signature($xmlfile))');
});

test('GHSA-6gr7-53g8-vchq: auth login redirect path no longer relies on referer substring trust', function () {
	$src = file_get_contents(__DIR__ . '/../../../../lib/auth.php');

	expect($src)->toContain('function auth_login_redirect');
	expect($src)->toContain('validate_redirect_url($redirect_url)');
	expect($src)->toContain("validate_redirect_url(\$_SERVER['HTTP_REFERER'])");
});

test('GHSA-84q3-92xc-c3pf: ORDER BY inputs pass through allowlist validation helper', function () {
	$src = file_get_contents(__DIR__ . '/../../../../utilities.php');

	expect($src)->toMatch('/\$sql_where\s+" \. get_order_string\(\) \. "\s+LIMIT/');
});

test('GHSA-84q3-92xc-c3pf: user_group_admin ORDER BY inputs pass through allowlist validation helper', function () {
	$src = file_get_contents(__DIR__ . '/../../../../user_group_admin.php');

	expect($src)->toMatch('/GROUP BY uag\.id\s+" \. get_order_string\(\) \. "/');
});

test('GHSA-8522-5p3m-754c: script-server PHP binary path is shell-escaped before execution', function () {
	$src = file_get_contents(__DIR__ . '/../../../../cmd_realtime.php');

	expect($src)->toContain("cacti_escapeshellcmd(read_config_option('path_php_binary'))");
});

test('GHSA-fwmp-mq8j-4r8f: remote agent proc_open command escapes binary and script path', function () {
	$src = file_get_contents(__DIR__ . '/../../../../remote_agent.php');

	expect($src)->toContain("\$php_bin  = cacti_escapeshellcmd(read_config_option('path_php_binary'));");
	expect($src)->toContain("\$srv_path = cacti_escapeshellarg(\$config['base_path'] . '/script_server.php');");
});

test('package file writes use anchored script/resource paths and canonical validation', function () {
    $src = file_get_contents(dirname(__DIR__, 4) . '/lib/import.php');
    expect($src)->toContain("preg_match('#^(plugins/[A-Za-z0-9_-]+/)?(scripts|resource)/#', \$normalized_name)")
        ->and($src)->toContain("validate_relative_path_within(\$normalized_name, \$config['base_path'])")
        ->and($src)->toContain('if ($filename === false)');
});

test('graph_realtime nolegend validation accepts only complete boolean strings', function () {
    $src = file_get_contents(dirname(__DIR__, 4) . '/graph_realtime.php');
    expect(preg_match("/get_filter_request_var\\('graph_nolegend'.*?'regexp' => '([^']+)'/", $src, $match))->toBe(1);
    foreach (array('true', 'false') as $value) { expect(preg_match($match[1], $value))->toBe(1); }
    foreach (array('untrue', 'falsex', 'true|false', '', '<true>') as $value) { expect(preg_match($match[1], $value))->toBe(0); }
});

test('GHSA-pf37-v86f-5xwp: reports graph_name_regexp filter uses db_qstr_rlike helper', function () {
	$src = file_get_contents(__DIR__ . '/../../../../lib/reports.php');

	expect($src)->toContain("db_qstr_rlike(\$item['graph_name_regexp'])");
});
