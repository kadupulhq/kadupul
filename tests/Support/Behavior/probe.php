<?php
/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Adapter for legacy public PHP APIs. A rewrite supplies an equivalent adapter.
$mode = $argv[1] ?? '';
$argv = [$argv[0]];
$_SERVER['argv'] = $argv;
chdir('/var/www/html');
require '/var/www/html/include/cli_check.php';
// global.php installs CactiErrorHandler, displacing the prepend handler.
// require_once cannot re-arm it, so call the installer directly.
behavior_install_error_handler();
require_once $config['base_path'] . '/lib/snmp.php';
require_once $config['base_path'] . '/lib/rrd.php';
$out = [];

function behavior_plugin_callback_count() {
    $log = '/artifacts/plugin.jsonl';
    return is_file($log) ? substr_count((string) file_get_contents($log), "\n") : 0;
}

if ($mode === 'types') {
    global $_CACTI_REQUEST;
    /** @legacy-behavior Preserve values and PHP types, including null vs empty. */
    foreach ([null, false, true, '', '0', 0, '1', 1, '12.5', '12oops', [], ['unexpected' => 1]] as $v) {
        $row = ['input' => $v];
        foreach (['cacti_sizeof', 'sanitize_search_string'] as $function) {
            try { $row[$function] = ['value' => $function($v)]; }
            catch (Throwable $e) { $row[$function] = ['exception' => get_class($e), 'message' => $e->getMessage()]; }
        }
        $_GET = ['value' => $v]; $_POST = []; $_REQUEST = $_GET;
        unset($_SESSION['sess_request']);
        // get_request_var() memoizes into $_CACTI_REQUEST. Without clearing it,
        // every row after the first non-null input reads back the first cached
        // value and the matrix measures the cache, not the input.
        $_CACTI_REQUEST = [];
        $row['request'] = get_request_var('value');
        $row['missing'] = get_request_var('missing');
        $out[] = $row;
    }
    /**
     * @legacy-behavior @compatibility-contract
     * get_request_var() caches per request name in the $_CACTI_REQUEST global.
     * Once a name is cached, a later change to $_REQUEST is ignored for the rest
     * of the request. Callers depend on this; a rewrite must reproduce it.
     */
    $_CACTI_REQUEST = [];
    $_GET = ['cached' => 'first']; $_POST = []; $_REQUEST = $_GET;
    $first = get_request_var('cached');
    $_GET = ['cached' => 'second']; $_REQUEST = $_GET;
    $out[] = ['request_var_cache' => ['first_read' => $first, 'after_superglobal_change' => get_request_var('cached')]];
    $out[] = ['db_empty_row' => db_fetch_row('SELECT id FROM host WHERE id=-1'),
        'db_empty_cell' => db_fetch_cell('SELECT id FROM host WHERE id=-1'),
        'db_empty_assoc' => db_fetch_assoc('SELECT id FROM host WHERE id=-1')];
} elseif ($mode === 'snmp') {
    foreach (['.1.3.6.1.2.1.1.1.0', '.1.3.6.1.4.1.8072.9999.1'] as $oid) {
        $out[$oid] = cacti_snmp_get('snmp', 'public', $oid, 2, '', '', '', '', '', '', 161, 500, 0);
    }
} elseif ($mode === 'plugin') {
    /**
     * @compatibility-contract Hook dispatch, argument shape and return handling.
     * The filter is transparent, so its return value alone cannot distinguish a
     * registered hook from an absent one. Count recorded callbacks across the
     * call to make "did the plugin run" observable in the golden itself.
     */
    $before = behavior_plugin_callback_count();
    $out['event'] = api_plugin_hook('compatibility_event', 'payload', ['value' => 42]);
    foreach ([null, false, '', '0', 0, [], ['value' => 42]] as $value) {
        $out['filter'][] = ['input' => $value, 'result' => api_plugin_hook_function('compatibility_filter', $value)];
    }
    $out['callbacks_observed'] = behavior_plugin_callback_count() - $before;
    $out['registered_hooks'] = db_fetch_assoc("SELECT hook, `function`, status FROM plugin_hooks WHERE name='compatibility_test' ORDER BY hook");
    $out['plugin_status'] = db_fetch_cell("SELECT status FROM plugin_config WHERE directory='compatibility_test'");
} elseif ($mode === 'warnings') {
    // Harness calibration; not claimed as observed application warnings.
    trigger_error('behavior capture calibration', E_USER_WARNING);
    try { strlen([]); } catch (Throwable $e) { $out = ['exception' => get_class($e), 'message' => $e->getMessage()]; }
} elseif ($mode === 'graph') {
    /**
     * Pick a graph whose RRD actually exists. Targeting a never-polled device
     * recorded "RRD file does not exist" with an empty source, which looks like
     * a captured contract and asserts nothing about graph generation.
     */
    /**
     * Follow the real relationship: a graph item names a task item, which is a
     * data_template_rrd row, which points at the data_local row that owns the
     * RRD file. Joining without those keys is a Cartesian product that answers
     * "does any data source exist" rather than "does this graph have one".
     */
    $id = (int) db_fetch_cell('SELECT MIN(gti.local_graph_id)
        FROM graph_templates_item AS gti
        INNER JOIN data_template_rrd AS dtr ON dtr.id = gti.task_item_id
        INNER JOIN data_local AS dl ON dl.id = dtr.local_data_id
        WHERE gti.local_graph_id > 0');
    if (!$id) { throw new RuntimeException('No graph is bound to a data source'); }
    $options = ['graph_start' => 1700000000, 'graph_end' => 1700003600, 'print_source' => 1];
    ob_start(); $result = rrdtool_function_graph($id, 0, $options); $source = ob_get_clean();
    $out = ['result' => $result, 'source' => $source];
} else { throw new RuntimeException('Unknown probe'); }
echo "\nBEHAVIOR_JSON=" . json_encode($out, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
