<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression tests for the lts/1.2 defensive security hardening audit.
 * Exercises runtime behavior of fixed functions in isolated PHP 8.1 subprocesses
 * without altering global namespace state across Pest test files.
 */

require_once dirname(__DIR__, 2) . '/Helpers/AuthEntryProbe.php';
require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

test('get_simple_device_perms queries device permission type 3', function () {
	$src  = file_get_contents(dirname(__DIR__, 3) . '/lib/auth.php');
	$body = test_php_function_source($src, 'get_simple_device_perms');

	expect($body)->toContain('type = 3')
		->and($body)->not->toContain('type = 2');
});

test('validate_redirect_url rejects double-encoded and foreign scheme targets', function () {
	$src     = file_get_contents(dirname(__DIR__, 3) . '/lib/html_utility.php');
	$fn      = test_php_function_source($src, 'validate_redirect_url');
	$funcs   = file_get_contents(dirname(__DIR__, 3) . '/lib/functions.php');
	$is_enc  = test_php_function_source($funcs, 'is_urlencoded');
	$san_uri = test_php_function_source($funcs, 'sanitize_uri');

	$code = "<?php\n" . $is_enc . "\n" . $san_uri . "\n" . $fn . "\n"
		. "\$cases = json_decode(stream_get_contents(STDIN), true);\n"
		. "\$out = array();\n"
		. "foreach (\$cases as \$k => \$u) {\n"
		. "\t\$out[\$k] = validate_redirect_url(\$u, 'index.php');\n"
		. "}\n"
		. "echo json_encode(\$out);\n";

	$result = cacti_test_run_php_source($code, array(
		'safe_rel'     => 'graphs.php?action=view&id=5',
		'proto_rel'    => '//evil.example/phish',
		'enc_proto'    => '%2f%2fevil.example/phish',
		'double_enc'   => '%252f%252fevil.example/phish',
		'backslash'    => '/\\evil.example/phish',
		'js_scheme'    => 'javascript:alert(1)',
		'enc_js'       => 'java%0ascript:alert(1)',
		'crlf'         => "index.php\r\nSet-Cookie: a=b",
	));

	expect($result['safe_rel'])->toBe('graphs.php?action=view&id=5')
		->and($result['proto_rel'])->toBe('index.php')
		->and($result['enc_proto'])->toBe('index.php')
		->and($result['double_enc'])->toBe('index.php')
		->and($result['backslash'])->toBe('index.php')
		->and($result['js_scheme'])->toBe('index.php')
		->and($result['enc_js'])->toBe('index.php')
		->and($result['crlf'])->toBe('index.php');
});

test('is_ipaddress validates IPv6 zone indices and rejects IPv4 zone suffixes', function () {
	$src = file_get_contents(dirname(__DIR__, 3) . '/lib/functions.php');
	$fn  = test_php_function_source($src, 'is_ipaddress');

	$code = "<?php\n" . $fn . "\n"
		. "\$cases = json_decode(stream_get_contents(STDIN), true);\n"
		. "\$out = array();\n"
		. "foreach (\$cases as \$k => \$ip) {\n"
		. "\t\$out[\$k] = is_ipaddress(\$ip);\n"
		. "}\n"
		. "echo json_encode(\$out);\n";

	$result = cacti_test_run_php_source($code, array(
		'ipv4_ok'      => '192.0.2.10',
		'ipv4_zone'    => '192.0.2.10%eth0',
		'ipv6_ok'      => '2001:db8::1',
		'ipv6_zone_ok' => 'fe80::1%eth0',
		'ipv6_badzone' => 'fe80::1%eth0;id',
		'ipv6_space'   => 'fe80::1%eth0 --bad',
		'ipv6_empty'   => 'fe80::1%',
	));

	expect($result['ipv4_ok'])->toBeTrue()
		->and($result['ipv4_zone'])->toBeFalse()
		->and($result['ipv6_ok'])->toBeTrue()
		->and($result['ipv6_zone_ok'])->toBeTrue()
		->and($result['ipv6_badzone'])->toBeFalse()
		->and($result['ipv6_space'])->toBeFalse()
		->and($result['ipv6_empty'])->toBeFalse();
});

test('substitute_host_data omits SNMP credentials unless explicitly opted in', function () {
	$src = file_get_contents(dirname(__DIR__, 3) . '/lib/variables.php');
	$fn  = test_php_function_source($src, 'substitute_host_data');

	$code = "<?php\n"
		. "define('SQL_NO_CACHE', '');\n"
		. "function cacti_sizeof(\$a) { return is_array(\$a) ? count(\$a) : 0; }\n"
		. "function db_fetch_row_prepared(\$sql, \$params = array()) {\n"
		. "\treturn array(\n"
		. "\t\t'id' => 7, 'hostname' => 'router1.example', 'description' => 'Core',\n"
		. "\t\t'snmp_community' => 'supersecret', 'snmp_version' => 3,\n"
		. "\t\t'snmp_username' => 'snmpuser', 'snmp_password' => 'authpass',\n"
		. "\t\t'snmp_auth_protocol' => 'SHA', 'snmp_priv_passphrase' => 'privpass',\n"
		. "\t\t'snmp_priv_protocol' => 'AES', 'snmp_context' => 'ctx',\n"
		. "\t\t'snmp_engine_id' => '8000', 'snmp_port' => 161, 'snmp_timeout' => 500,\n"
		. "\t\t'max_oids' => 10, 'notes' => '', 'external_id' => '', 'site_id' => 0,\n"
		. "\t\t'poller_id' => 1, 'host_template_id' => 1, 'site_name' => 'HQ',\n"
		. "\t\t'location' => 'Rack1', 'polling_time' => 0.1, 'avg_time' => 1.0,\n"
		. "\t\t'cur_time' => 1.0, 'availability' => 99.9,\n"
		. "\t\t'snmp_sysDescr' => '', 'snmp_sysObjectID' => '', 'snmp_sysContact' => '',\n"
		. "\t\t'snmp_sysLocation' => '', 'snmp_sysName' => '', 'snmp_sysUpTimeInstance' => 0,\n"
		. "\t\t'ping_retries' => 1\n"
		. "\t);\n"
		. "}\n"
		. "function get_uptime(\$host) { return '1 day'; }\n"
		. "function api_plugin_hook_function(\$hook, \$data) { return \$data; }\n"
		. "function read_config_option(\$k) { return ''; }\n"
		. "function cacti_escapeshellarg(\$s) { return escapeshellarg(\$s); }\n"
		. $fn . "\n"
		. "\$tpl = 'host=|host_hostname| comm=|host_snmp_community| pass=|host_snmp_password| priv=|host_snmp_priv_passphrase|';\n"
		. "echo json_encode(array(\n"
		. "\t'default' => substitute_host_data(\$tpl, '|', '|', 7),\n"
		. "\t'opted'   => substitute_host_data(\$tpl, '|', '|', 7, true),\n"
		. "));\n";

	$result = cacti_test_run_php_source($code, array());

	expect($result['default'])->toContain('host=router1.example')
		->and($result['default'])->not->toContain('supersecret')
		->and($result['default'])->not->toContain('authpass')
		->and($result['default'])->not->toContain('privpass')
		->and($result['opted'])->toContain('supersecret')
		->and($result['opted'])->toContain('authpass')
		->and($result['opted'])->toContain('privpass');
});

test('realtime_purge_cache ignores symlinks and never deletes symlink targets', function () {
	$src = file_get_contents(dirname(__DIR__, 3) . '/poller_maintenance.php');
	$fn  = test_php_function_source($src, 'realtime_purge_cache');
	$lib = file_get_contents(dirname(__DIR__, 3) . '/lib/functions.php');
	$within = test_php_function_source($lib, 'cacti_path_is_within');

	$base   = sys_get_temp_dir() . '/kadupul-rt-symlink-' . bin2hex(random_bytes(6));
	$cache  = $base . '/cache';
	$victim = $base . '/victim.png';
	mkdir($cache, 0700, true);
	file_put_contents($victim, 'precious');
	touch($victim, time() - 10800);
	file_put_contents($cache . '/old.png', 'stale');
	touch($cache . '/old.png', time() - 10800);
	symlink($victim, $cache . '/evil.png');

	$code = "<?php\n"
		. "function read_config_option(\$k) { return " . var_export($cache, true) . "; }\n"
		. "function db_execute(\$sql) {}\n"
		. "function cacti_log(\$m, ...\$a) {}\n"
		. "function cacti_log_safe_value(\$v) { return \$v; }\n"
		. $within . "\n"
		. $fn . "\n"
		. "realtime_purge_cache();\n"
		. "echo json_encode(array(\n"
		. "\t'victim_exists' => file_exists(" . var_export($victim, true) . "),\n"
		. "\t'symlink_exists' => is_link(" . var_export($cache . '/evil.png', true) . "),\n"
		. "\t'stale_deleted' => !file_exists(" . var_export($cache . '/old.png', true) . "),\n"
		. "));\n";

	try {
		$result = cacti_test_run_php_source($code, array());
		expect($result['victim_exists'])->toBeTrue()
			->and($result['symlink_exists'])->toBeTrue()
			->and($result['stale_deleted'])->toBeTrue();
	} finally {
		@unlink($cache . '/evil.png');
		@unlink($cache . '/old.png');
		@unlink($victim);
		@rmdir($cache);
		@rmdir($base);
	}
});

test('logrotate_file_rotate refuses to rotate a symlinked log file', function () {
	$src = file_get_contents(dirname(__DIR__, 3) . '/poller_maintenance.php');
	$fn  = test_php_function_source($src, 'logrotate_file_rotate');

	$base   = sys_get_temp_dir() . '/kadupul-logrot-symlink-' . bin2hex(random_bytes(6));
	$target = $base . '/target.txt';
	$link   = $base . '/cacti.log';
	mkdir($base, 0700, true);
	file_put_contents($target, 'keep-me');
	symlink($target, $link);

	$code = "<?php\n"
		. "\$logs = array();\n"
		. "function cacti_log(\$m, ...\$a) { \$GLOBALS['logs'][] = \$m; }\n"
		. $fn . "\n"
		. "\$rc = logrotate_file_rotate('Cacti', " . var_export($link, true) . ", new DateTime('2026-01-01'));\n"
		. "echo json_encode(array('rc' => \$rc, 'target_intact' => file_exists(" . var_export($target, true) . "), 'logs' => \$logs));\n";

	try {
		$result = cacti_test_run_php_source($code, array());
		expect($result['rc'])->toBe(0)
			->and($result['target_intact'])->toBeTrue()
			->and(implode("\n", $result['logs']))->toContain('SECURITY');
	} finally {
		@unlink($link);
		@unlink($target);
		@rmdir($base);
	}
});

test('png2jpeg and png2gif convert in memory without touching temp files', function () {
	$src = file_get_contents(dirname(__DIR__, 3) . '/lib/reports.php');
	$jpg = test_php_function_source($src, 'png2jpeg');
	$gif = test_php_function_source($src, 'png2gif');

	expect($jpg)->toContain('imagecreatefromstring')
		->and($jpg)->not->toContain('getmypid')
		->and($jpg)->not->toContain('unlink(')
		->and($gif)->toContain('imagecreatefromstring')
		->and($gif)->not->toContain('getmypid')
		->and($gif)->not->toContain('unlink(');
});

test('cli/md5sum.php uses hash_equals, exits non-zero on mismatch, and checks canonical containment', function () {
	$src = file_get_contents(dirname(__DIR__, 3) . '/cli/md5sum.php');

	expect($src)->toContain('hash_equals(')
		->and($src)->toContain('exit(EXIT_MD5ERR)')
		->and($src)->toContain('cacti_path_is_within(');
});

test('rrd.php validates business_hours_color, textalign, and alpha values', function () {
	$src = file_get_contents(dirname(__DIR__, 3) . '/lib/rrd.php');

	expect($src)->toContain("preg_match('/^[0-9A-Fa-f]{6}(?:[0-9A-Fa-f]{2})?\\z/'")
		->and($src)->toContain("in_array(\$graph_item['textalign'], array('left', 'right', 'justified', 'center'), true)");
});

test('cacti_escapeshellarg escapes Windows double quotes safely', function () {
	$src = file_get_contents(dirname(__DIR__, 3) . '/lib/functions.php');
	$fn  = test_php_function_source($src, 'cacti_escapeshellarg');

	$code = "<?php\n"
		. "define('CACTI_ESCAPE_CHARACTER', '\"');\n"
		. "\$config = array('cacti_server_os' => 'win32');\n"
		. $fn . "\n"
		. "\$out = cacti_escapeshellarg('abc\\\\\" & calc.exe');\n"
		. "echo json_encode(array('out' => \$out));\n";

	$result = cacti_test_run_php_source($code, array());
	expect($result['out'])->toStartWith('"')
		->and($result['out'])->toEndWith('"')
		->and($result['out'])->toContain('\\\\\\"');
});
