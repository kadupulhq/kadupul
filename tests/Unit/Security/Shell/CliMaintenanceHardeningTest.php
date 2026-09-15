<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

$base                  = dirname(__DIR__, 4);
$auditDatabaseSource   = file_get_contents($base . '/cli/audit_database.php');
$batchgapfixSource     = file_get_contents($base . '/cli/batchgapfix.php');
$floatRrdfilesSource   = file_get_contents($base . '/cli/float_rrdfiles.php');
$updateHeartbeatSource = file_get_contents($base . '/cli/update_heartbeat.php');

test('database sourced RRD paths are escaped before shell execution', function () use ($batchgapfixSource, $floatRrdfilesSource, $updateHeartbeatSource) {
	expect($batchgapfixSource)->toContain('cacti_escapeshellarg($rrdfile[\'data_source_path\'])')
		->and($batchgapfixSource)->toContain('exec_background($php_bin, $args)')
		->and($floatRrdfilesSource)->toContain("cacti_escapeshellarg(\$rrdtool_bin) . ' dump ' . cacti_escapeshellarg(\$rrd_path)")
		->and($floatRrdfilesSource)->toContain("cacti_escapeshellarg(\$tmp_file) . ' ' . cacti_escapeshellarg(\$rrd_path)")
		->and($updateHeartbeatSource)->toContain("cacti_escapeshellarg(\$f['rrd'])")
		->and($updateHeartbeatSource)->toContain("cacti_escapeshellarg(\$ds . ':' . \$new_heartbeat)");
});

test('CLI subprocesses pass background arguments as arrays', function () use ($batchgapfixSource, $floatRrdfilesSource) {
	expect($batchgapfixSource)->toContain('exec_background($php_bin, $args)')
		->and($floatRrdfilesSource)->toContain('exec_background($php_binary, $args)')
		->and($batchgapfixSource)->toContain('$php_bin = PHP_BINARY;')
		->and($floatRrdfilesSource)->toContain('$php_binary = PHP_BINARY;');
});

test('float rrdfiles creates its 1.2.31 temporary names exclusively and cleans up', function () use ($floatRrdfilesSource) {
	expect($floatRrdfilesSource)->toContain("\$tmp_file = \$tmp_dir . '/' . \$local_data_id . '.xml';")
		->and($floatRrdfilesSource)->toContain('$fp       = cacti_cli_create_file($tmp_file);')
		->and($floatRrdfilesSource)->toContain("\$lf = cacti_cli_open_log('/tmp/clearer.log');")
		->and($floatRrdfilesSource)->not->toContain("tempnam(\$tmp_dir, 'cacti_float_')")
		->and($floatRrdfilesSource)->not->toContain("fopen('/tmp/clearer.log'")
		->and(substr_count($floatRrdfilesSource, 'cacti_cli_remove_file($fp, $tmp_file);'))->toBe(5)
		->and($floatRrdfilesSource)->not->toContain('unlink($tmp_file)')
		->and($floatRrdfilesSource)->toContain('if (!cacti_cli_path_is_handle($fp, $tmp_file)) {')
		->and($floatRrdfilesSource)->toContain('if (float_rrdfile($data[\'rrd_path\'], $data[\'local_data_id\'], $step, $start_time, $end_time)) {')
		->and($floatRrdfilesSource)->toContain('$lf         = false;')
		->and($floatRrdfilesSource)->toContain('$file_debug = is_resource($lf);')
		->and($floatRrdfilesSource)->not->toContain('$seebug = is_resource($lf);')
		->and($floatRrdfilesSource)->toContain("\$rrdtool_bin = 'rrdtool';")
		->and($floatRrdfilesSource)->not->toContain("cacti_float_rrdfiles.log");
});

test('mysql option file values are quoted and escaped', function () use ($auditDatabaseSource) {
	preg_match('/function audit_database_option_value\(.*?^}\R/ms', $auditDatabaseSource, $matches);

	expect($matches)->toHaveKey(0);
	expect($auditDatabaseSource)->toContain('audit_database_option_value - quote and escape a MySQL option-file value')
		->and($auditDatabaseSource)->toContain('audit_database_defaults_file - writes the database credentials');

	eval($matches[0]); // nosemgrep: php.lang.security.eval-use.eval-use

	expect(audit_database_option_value('abc#def;ghi'))->toBe('"abc#def;ghi"')
		->and(audit_database_option_value("line\nnext"))->toBe('"line\\nnext"')
		->and(audit_database_option_value('a"b\\c'))->toBe('"a\\"b\\\\c"')
		->and(audit_database_option_value("bad\0value"))->toBeFalse();
});
