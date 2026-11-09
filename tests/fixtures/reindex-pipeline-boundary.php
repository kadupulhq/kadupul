<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
namespace ReindexPipelineFixture;

use PDO;
use RuntimeException;
use Throwable;

// Only acquisition, item construction and unrelated presentation/plugin effects
// are boundaries. Coordinator, remap, SQL and both cache-writing layers execute.
$root = dirname(__DIR__, 2);
foreach (array('lib/data_query.php' => array('run_data_query_work'),
	'lib/utility.php' => array('update_poller_cache_from_query','poller_update_poller_cache_from_buffer')) as $file => $functions) {
	$source = file_get_contents($root . '/' . $file);
	if ($source === false) { throw new RuntimeException('Missing pipeline source'); }
	foreach ($functions as $name) { eval('namespace ReindexPipelineFixture; use PDO; use RuntimeException; use Throwable;' . \test_php_function_source($source, $name)); }
}
function db_column_exists($table, $column) { return true; }
function query_snmp_host($host, $query) { return true; }
function update_reindex_cache($host, $query) { return $GLOBALS['scenario'] !== 'pipeline-reindex-failure'; }
function update_data_source_title_cache_from_host(...$arguments) {}
function update_graph_title_cache_from_host(...$arguments) {}
function automation_execute_data_query($host, $query) { $GLOBALS['pipeline_effects'][] = 'automation'; }
function api_plugin_hook_function($hook, $data) { $GLOBALS['pipeline_effects'][] = $hook; return $data; }
function set_config_option($key, $value) {}
function update_poller_cache($source, $commit = false) {
	$GLOBALS['pipeline_builds']++;
	return array('(' . $source['id'] . ",1,1,0,'fixture.invalid','',0,0,'','','','','','','',161,'traffic','/unused',1,300,0,'oid." . $source['snmp_index'] . "','','',1)");
}
