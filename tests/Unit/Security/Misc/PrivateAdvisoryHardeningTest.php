<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

$colorSource         = file_get_contents(__DIR__ . '/../../../../color.php');
$dataSourcesSource   = file_get_contents(__DIR__ . '/../../../../data_sources.php');
$dataTemplatesSource = file_get_contents(__DIR__ . '/../../../../data_templates.php');
$databaseSource      = file_get_contents(__DIR__ . '/../../../../lib/database.php');
$functionsSource     = file_get_contents(__DIR__ . '/../../../../lib/functions.php');
$pluginsSource       = file_get_contents(__DIR__ . '/../../../../lib/plugins.php');
$pollerLibSource     = file_get_contents(__DIR__ . '/../../../../lib/poller.php');
$rrdSource           = file_get_contents(__DIR__ . '/../../../../lib/rrd.php');
$boostSource         = file_get_contents(__DIR__ . '/../../../../lib/boost.php');
$realtimeSource      = file_get_contents(__DIR__ . '/../../../../poller_realtime.php');
$spikekillSource     = file_get_contents(__DIR__ . '/../../../../poller_spikekill.php');

test('rrd maximum and minimum validators are fully anchored', function () use ($dataSourcesSource, $dataTemplatesSource) {
	expect($dataSourcesSource)->toContain('^((-?([0-9]+(\.[0-9]*)?|[0-9]*\.[0-9]+)([eE][+\-]?[0-9]+)?)|U|\|query_ifSpeed\||\|query_ifHighSpeed\|)$');
	expect($dataTemplatesSource)->toContain('^((-?([0-9]+(\.[0-9]*)?|[0-9]*\.[0-9]+)([eE][+\-]?[0-9]+)?)|U|\|query_ifSpeed\|)$');
	expect($dataTemplatesSource)->toContain('^((-?([0-9]+(\.[0-9]*)?|[0-9]*\.[0-9]+)([eE][+\-]?[0-9]+)?)|U)$');
});

test('data source path rejects multiline values at form save', function () use ($dataSourcesSource) {
	expect($dataSourcesSource)->toContain("'data_source_path', '^[^\\r\\n]*$'");
});

test('rrdtool stdin command paths and bounds are validated at sinks', function () use ($functionsSource, $rrdSource, $boostSource) {
	expect($functionsSource)->toContain('function cacti_rrdtool_valid_path');
	expect($functionsSource)->toContain('function cacti_rrdtool_valid_bound');
	expect($functionsSource)->toContain('function cacti_rrdtool_valid_ds_name');
	expect($functionsSource)->toContain('function cacti_rrdtool_valid_ds_template');
	expect($functionsSource)->toContain('function cacti_log_safe_value');
	expect($functionsSource)->toContain('function cacti_rrdtool_valid_path_token');
	expect($rrdSource)->toContain('function rrdtool_build_path_command');
	expect($rrdSource)->toContain('function rrdtool_execute_path_command');
	expect($rrdSource)->toContain('function rrdtool_execute_restore_command');
	expect($rrdSource)->toContain('!cacti_rrdtool_valid_path($data_source_path)');
	expect($rrdSource)->toContain('!cacti_rrdtool_valid_bound($data_source[\'rrd_minimum\'])');
	expect($rrdSource)->toContain('!cacti_rrdtool_valid_ds_template($rrd_update_template)');
	expect($rrdSource)->toContain('rrdtool_execute_path_command(\'file_exists\', $rrd_path');
	expect($boostSource)->toContain('!cacti_rrdtool_valid_path($data_source_path)');
	expect($boostSource)->toContain('!cacti_rrdtool_valid_ds_template($rrd_update_template)');
	expect($boostSource)->toContain('rrdtool_execute_path_command(\'file_exists\', $rrd_path');

	// The bound check used to sit in both creators. It now runs once, in
	// rrd_create_definition(), which both of them call; the copies had drifted
	// three ways and two of the differences cost data.
	expect($boostSource)->toContain('rrd_create_definition($local_data_id, \'BOOST\')');
	expect($rrdSource)->toContain('rrd_create_definition($local_data_id, \'POLLER\')');
});

test('shell command binaries and rrdfile arguments are escaped', function () use ($rrdSource, $pollerLibSource, $spikekillSource) {
	expect($rrdSource)->toContain('$rrdtool = cacti_escapeshellarg(read_config_option(\'path_rrdtool\'))');
	expect($rrdSource)->toContain('proc_open(cacti_escapeshellarg(read_config_option(\'path_rrdtool\'))');
	expect($pollerLibSource)->toContain('$safe_filename = cacti_escapeshellarg($filename)');
	expect($spikekillSource)->toContain("' --rrdfile=' . cacti_escapeshellarg(\$f)");
});

test('plugin ddl helpers validate identifiers and route through db_add_column', function () use ($databaseSource, $pluginsSource) {
	expect($databaseSource)->toContain('function db_is_safe_identifier');
	expect($databaseSource)->toContain('function db_is_safe_column_definition');
	expect($databaseSource)->toContain('function db_is_safe_table_definition');
	expect($databaseSource)->toContain('function db_build_column_definition_sql');
	// CREATE TABLE path must reject bad engines/columns before SQL composition.
	expect($databaseSource)->toContain('if (!db_is_safe_table_definition($data))');
	// Partial ALTER path must still reject injected engine/charset tokens.
	expect($databaseSource)->toContain("isset(\$data['type']) && !db_is_safe_table_option(\$data['type'])");
	expect($databaseSource)->toContain('(?:\s+(?:unsigned|signed|zerofill|binary))*');
	expect($databaseSource)->toContain('db_qstr($column[\'default\'], $db_conn)');
	expect($databaseSource)->toContain('AFTER `');
	expect($databaseSource)->toContain('db_format_index_create($data[\'primary\'])');
	expect($databaseSource)->toContain('db_qstr($data[\'comment\'], $db_conn)');
	expect($databaseSource)->toContain('DROP INDEX `$key`');
	expect($databaseSource)->toContain('!db_is_safe_identifier($table) || !db_is_safe_identifier($column)');
	expect($databaseSource)->toContain('!db_is_safe_identifier($table) || !db_is_safe_identifier($key)');
	expect($pluginsSource)->toContain('db_is_safe_identifier($table)');
	expect($pluginsSource)->toContain('db_add_column($table, $column)');
	expect($pluginsSource)->toContain('db_table_create($table, $data)');
	expect($pluginsSource)->toContain('DROP TABLE IF EXISTS `$table`');
});

test('db_update_table batches schema changes into one alter statement', function () use ($databaseSource) {
	$start = strpos($databaseSource, 'function db_update_table(');
	$end   = strpos($databaseSource, 'function db_format_index_create(', $start);

	expect($start)->not->toBeFalse()
		->and($end)->not->toBeFalse();

	$body = substr($databaseSource, $start, $end - $start);

	expect(substr_count($body, 'db_execute('))->toBe(1)
		->and($body)->toContain("implode(', ', \$alter_clauses)")
		->and($body)->not->toContain("\$table_encoding !== '' ? ' ' : 'DEFAULT '")
		->and($body)->toContain("'ADD ' . \$definition")
		->and($body)->toContain("'DROP COLUMN `'")
		->and($body)->toContain("'ADD INDEX `'")
		->and($body)->not->toContain('db_add_column(')
		->and($body)->not->toContain('db_remove_column(')
		->and($body)->not->toContain('db_add_index(');
});

test('realtime poller avoids direct rrdtool shell create', function () use ($realtimeSource) {
	expect($realtimeSource)->toContain('cacti_exec(read_config_option(\'path_php_binary\'), array(');
	expect($realtimeSource)->toContain('rrdtool_execute($command, false, RRDTOOL_OUTPUT_STDOUT, $rrdtool_pipe, \'POLLER\')');
	expect($realtimeSource)->not->toContain('shell_exec("$command_string $extra_args")');
	expect($realtimeSource)->not->toContain('shell_exec($command)');
});

test('color csv import uses csv parser, prepared statements, and escaped output', function () use ($colorSource) {
	expect($colorSource)->toContain("str_getcsv(\$color_line, ',', '\"', '\\\\')");
	expect($colorSource)->toContain("preg_match('/^[A-Fa-f0-9]{6}$/', \$hex)");
	expect($colorSource)->toContain('db_execute_prepared(\'INSERT INTO colors');
	expect($colorSource)->toContain('db_fetch_row_prepared(\'SELECT *');

	// Run the real processor so the check follows the escaping, not how the result line is assembled
	if (!function_exists('PrivateAdvisoryColorImport\color_import_processor')) {
		expect(preg_match('/^function color_import_processor\(.*?^}\R/ms', $colorSource, $processor))->toBe(1);

		eval('namespace PrivateAdvisoryColorImport;
			function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
			function html_escape($string) { return htmlspecialchars((string) $string, ENT_QUOTES, \'UTF-8\'); }
			function isset_request_var($name) { return false; }
			function db_fetch_row_prepared($sql, $params = array()) { return array(); }
			function db_execute_prepared($sql, $params = array()) { return true; }
			' . $processor[0]); // nosemgrep: php.lang.security.eval-use.eval-use
	}

	$lines   = array("name,hex\n", "\"<script>alert(1)</script>\",\"00FF00\"\n", "\"<b>x</b>\",\"<i>zz\"\n");
	$results = implode("\n", PrivateAdvisoryColorImport\color_import_processor($lines));

	expect($results)->toContain('INSERT SUCCEEDED: (')
		->and($results)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
		->and($results)->toContain('&lt;b&gt;x&lt;/b&gt;')
		->and($results)->toContain('&lt;i&gt;zz')
		->and($results)->not->toContain('<script>')
		->and($results)->not->toContain('<b>x</b>')
		->and($results)->not->toContain('<i>zz');
});
