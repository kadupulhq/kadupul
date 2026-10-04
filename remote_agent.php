<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require(__DIR__ . '/include/global.php');
require_once($config['base_path'] . '/lib/api_device.php');
require_once($config['base_path'] . '/lib/api_data_source.php');
include_once($config['base_path'] . '/lib/data_query.php');
require_once($config['base_path'] . '/lib/api_graph.php');
require_once($config['base_path'] . '/lib/api_tree.php');
require_once($config['base_path'] . '/lib/data_query.php');
require_once($config['base_path'] . '/lib/html_form_template.php');
require_once($config['base_path'] . '/lib/ping.php');
require_once($config['base_path'] . '/lib/poller.php');
require_once($config['base_path'] . '/lib/rrd.php');
require_once($config['base_path'] . '/lib/snmp.php');
require_once($config['base_path'] . '/lib/sort.php');
require_once($config['base_path'] . '/lib/template.php');
require_once($config['base_path'] . '/lib/utility.php');
require_once(__DIR__ . '/lib/remote_agent_auth.php');

$debug = false;
$remote_agent_authorized_poller_id = 0;

if ($config['poller_id'] > 1 && $config['connection'] == 'online') {
    if (get_nfilter_request_var('action') == 'runquery') {
        db_force_remote_cnn();
    }

    $poller_db_cnn_id = $remote_db_cnn_id;
} else {
    $poller_db_cnn_id = false;
}

if (!remote_client_authorized()) {
    http_response_code(403);
    print 'FATAL: Client authorization failed.  You are not authorized to use this service';
    exit;
}

set_default_action();

switch (get_request_var('action')) {
    case 'polldata':
        // Only let realtime polling run for a short time
        ini_set('max_execution_time', read_config_option('script_timeout'));

        debug('Start: Poling Data for Realtime');
        poll_for_data();
        debug('End: Poling Data for Realtime');

        break;
    case 'runquery':
        debug('Start: Running Data Query');
        remote_inventory_diagnostics("runquery");
        debug('End: Running Data Query');

        break;
    case 'ping':
        debug('Start: Pinging Device');
        if (get_nfilter_request_var("safe_diagnostics") === "1") {
            remote_inventory_diagnostics("ping");
        } else {
            ping_device();
        }
        debug('End: Pinging Device');

        break;
    case 'snmpget':
        debug('Start: Performing SNMP Get Request');
        get_snmp_data();
        debug('End: Performing SNMP Get Request');

        break;
    case 'snmpwalk':
        debug('Start: Performing SNMP Walk Request');
        get_snmp_data_walk();
        debug('End: Performing SNMP Walk Request');

        break;
    case 'graph_json':
        debug('Start: Performing Graph Request');
        get_graph_data();
        debug('End: Performing Graph Request');

        break;
    case 'discover':
        debug('Start:Performing Network Discovery Request');
        run_remote_discovery();
        debug('End:Performing Network Discovery Request');

        break;
    default:
        if (!api_plugin_hook_function('remote_agent', get_request_var('action'))) {
            debug('WARNING: Unknown Agent Request');
            print 'Unknown Agent Request';
        }
}

exit;

function remote_inventory_diagnostics($operation)
{
    global $config;
    unset($_SESSION['debug_log'], $config['debug_log']);
    $scope = \Kadupul\Inventory\Infrastructure\Legacy\DeviceDiagnosticScope::class;
    if (!remote_agent_host_belongs_to_authorized_poller(get_filter_request_var("host_id"))) {
        http_response_code(403);
        echo json_encode(["error" => "diagnostics_unavailable"]);
        return;
    }
    $scope::begin();
    $level = ob_get_level();
    ob_start();
    try {
        if ($operation === 'ping') {
            ping_device();
            $payload = ['output' => (string) ob_get_contents()];
        } else {
            $id = get_filter_request_var('host_id');
            $query = get_filter_request_var('data_query_id');
            if ($id < 1 || $query < 1) {
                throw new RuntimeException('Invalid diagnostic request');
            }
            $completed = run_data_query($id, $query);
            $output = (string) ob_get_contents();
            $payload = $output === '' ? ['result' => $completed, 'data_query' => $_SESSION['debug_log']['data_query'] ?? $config['debug_log']['data_query'] ?? []] : json_decode($output, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new RuntimeException('Invalid diagnostic response');
            }
        }
        $payload = $scope::finish($payload);
        $payload['diagnostics_sanitized'] = true;
    } catch (Throwable) {
        http_response_code(502);
        $payload = ['error' => 'diagnostics_unavailable'];
    } finally {
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
        $scope::discard();
        unset($_SESSION['debug_log'], $config['debug_log']);
    }
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
}

function debug($message)
{
    global $debug;

    if ($debug) {
        cacti_log("REMOTE DEBUG: " . trim($message), false, 'WEBSVCS');
    }
}

function remote_agent_strip_domain($host)
{
    if (strpos($host, '.') !== false) {
        $parts = explode('.', $host);
        return $parts[0];
    } else {
        return $host;
    }
}

function remote_agent_auth_cache_get($key)
{
    if (function_exists('apcu_fetch')) {
        $success = false;
        $result = apcu_fetch($key, $success);

        if ($success) {
            return is_int($result) && $result >= 0 ? $result : null;
        }
    }

    return null;
}

function remote_agent_auth_cache_set($key, $value, $ttl = 30)
{
    if (function_exists('apcu_store')) {
        apcu_store($key, (int) $value, $ttl);
    }
}

function remote_client_authorized()
{
    global $poller_db_cnn_id, $remote_agent_authorized_poller_id;

    $remote_agent_authorized_poller_id = 0;
    $client_addr = get_client_addr();
    if ($client_addr === false || !filter_var($client_addr, FILTER_VALIDATE_IP)) {
        return false;
    }
    $pollers = db_fetch_assoc('SELECT * FROM poller WHERE disabled = ""', true, $poller_db_cnn_id);
    $remote_agent_authorized_poller_id = remote_agent_resolve_poller(
        $client_addr,
        is_array($pollers) ? $pollers : array(),
        'gethostbyaddr',
        static function ($name) {
            return dns_get_record($name, DNS_A | DNS_AAAA);
        },
        'remote_agent_auth_cache_get',
        'remote_agent_auth_cache_set'
    );
    if ($remote_agent_authorized_poller_id <= 0) {
        cacti_log("Unauthorized or ambiguous remote agent access attempt from $client_addr", false, 'SECURITY');

        return false;
    }

    return true;
}

function get_graph_data()
{
    get_filter_request_var('graph_start');
    get_filter_request_var('graph_end');
    get_filter_request_var('graph_height');
    get_filter_request_var('graph_width');
    get_filter_request_var('local_graph_id');
    get_filter_request_var('rra_id');
    get_filter_request_var('graph_theme', FILTER_CALLBACK, array('options' => 'sanitize_search_string'));
    get_filter_request_var('graph_nolegend', FILTER_CALLBACK, array('options' => 'sanitize_search_string'));

    $local_graph_id   = get_filter_request_var('local_graph_id');
    $rra_id           = get_filter_request_var('rra_id');

    $graph_data_array = array();

    foreach (array('graph_start' => FILTER_VALIDATE_MAX_DATE_AS_INT, 'graph_end' => FILTER_VALIDATE_MAX_DATE_AS_INT, 'graph_height' => 3000, 'graph_width' => 3000) as $field => $maximum) {
        if (!isempty_request_var($field) && get_request_var($field) < $maximum) {
            $graph_data_array[$field] = get_request_var($field);
        }
    }
    foreach (array('graph_nolegend' => 'graph_nolegend', 'show_source' => 'print_source') as $field => $option) {
        if (!isempty_request_var($field)) {
            $graph_data_array[$option] = get_request_var($field);
        }
    }

    /* disable cache check */
    if (isset_request_var('disable_cache')) {
        $graph_data_array['disable_cache'] = true;
    }

    /* set the theme */
    if (isset_request_var('graph_theme')) {
        $graph_data_array['graph_theme'] = cacti_validate_theme(get_request_var('graph_theme'));
    }

    $user = (int) get_filter_request_var('effective_user');
    if ($user <= 0 || (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM user_auth WHERE id = ? AND enabled = "on" AND locked = ""', array($user)) !== 1 || !is_graph_allowed($local_graph_id, $user) || !remote_agent_graph_belongs_to_authorized_poller($local_graph_id)) {
        print 'GRAPH ACCESS DENIED';

        return false;
    }

    $graph_data_array['graphv'] = true;

    $xport_options = array();

    print @rrdtool_function_graph($local_graph_id, $rra_id, $graph_data_array, null, $xport_options, $user);

    return true;
}

/** Keep the registered device's SNMP settings identical across request types. */
function remote_agent_snmp_session(array $host)
{
    return cacti_snmp_session(
        $host['hostname'],
        $host['snmp_community'],
        $host['snmp_version'],
        $host['snmp_username'],
        $host['snmp_password'],
        $host['snmp_auth_protocol'],
        $host['snmp_priv_passphrase'],
        $host['snmp_priv_protocol'],
        $host['snmp_context'],
        $host['snmp_engine_id'],
        $host['snmp_port'],
        $host['snmp_timeout'],
        $host['ping_retries'],
        $host['max_oids']
    );
}

function get_snmp_data()
{
    $host_id = get_filter_request_var('host_id');
    $oid     = get_nfilter_request_var('oid');

    if (!remote_agent_host_belongs_to_authorized_poller($host_id)) {
        print 'U';

        return;
    }

    if (!is_string($oid) || !preg_match('/^[0-9.]+$/', $oid)) {
        print 'U';
        return;
    }

    $output = '';

    if (!empty($host_id)) {
        $host = db_fetch_row_prepared('SELECT * FROM host WHERE id = ?', array($host_id));

        if (!cacti_sizeof($host)) {
            print 'U';
            return;
        }

        $session = remote_agent_snmp_session($host);

        if ($session === false) {
            $output = 'U';
        } else {
            $output = cacti_snmp_session_get($session, $oid);
            $session->close();
        }
    }

    print $output;
}

function get_snmp_data_walk()
{
    $host_id = get_filter_request_var('host_id');
    $oid     = get_nfilter_request_var('oid');

    if (!remote_agent_host_belongs_to_authorized_poller($host_id)) {
        print 'U';

        return;
    }

    if (!is_string($oid) || !preg_match('/^[0-9.]+$/', $oid)) {
        print 'U';
        return;
    }

    $output = array();

    if (!empty($host_id)) {
        $host = db_fetch_row_prepared('SELECT * FROM host WHERE id = ?', array($host_id));

        if (!cacti_sizeof($host)) {
            print 'U';
            return;
        }

        $session = remote_agent_snmp_session($host);

        if ($session === false) {
            $output = 'U';
        } else {
            $output = cacti_snmp_session_walk($session, $oid);
            $session->close();
        }
    }

    if (cacti_sizeof($output)) {
        print json_encode($output);
    } else {
        print 'U';
    }
}

function ping_device()
{
    $host_id = get_filter_request_var('host_id');

    if (!remote_agent_host_belongs_to_authorized_poller($host_id)) {
        print 'U';

        return;
    }

    api_device_ping_device($host_id, true);
}

function poll_for_data()
{
    global $config;

    $local_data_ids = get_nfilter_request_var('local_data_ids');
    $host_id        = get_filter_request_var('host_id');
    $poller_id      = get_nfilter_request_var('poller_id');
    $return         = array();

    if (!remote_agent_host_belongs_to_authorized_poller($host_id)) {
        print json_encode($return);

        return;
    }

    /* ensure we have a valid poller_id */
    if (!preg_match('/^[a-z0-9]+$/i', $poller_id)) {
        return array();
    }

    $i = 0;

    if (cacti_sizeof($local_data_ids)) {
        foreach ($local_data_ids as $local_data_id) {
            input_validate_input_number($local_data_id);

            $items = db_fetch_assoc_prepared(
                'SELECT *
				FROM poller_item
				WHERE host_id = ?
				AND local_data_id = ?',
                array($host_id, $local_data_id)
            );

            $script_server_calls = db_fetch_cell_prepared(
                'SELECT COUNT(*)
				FROM poller_item
				WHERE host_id = ?
				AND local_data_id = ?
				AND action = 2',
                array($host_id, $local_data_id)
            );

            if (cacti_sizeof($items)) {
                foreach ($items as $item) {
                    switch ($item['action']) {
                        case POLLER_ACTION_SNMP: /* snmp */
                            if (($item['snmp_version'] == 0) || (($item['snmp_community'] == '') && ($item['snmp_version'] != 3))) {
                                $output = 'U';
                            } else {
                                $host = db_fetch_row_prepared('SELECT ping_retries, max_oids FROM host WHERE hostname = ?', array($item['hostname']));
                                $session = remote_agent_snmp_session(array_replace($item, $host));

                                if ($session === false) {
                                    $output = 'U';
                                } else {
                                    $output = cacti_snmp_session_get($session, $item['arg1']);
                                    $session->close();
                                }

                                if (prepare_validate_result($output) === false) {
                                    if (strlen($output) > 20) {
                                        $strout = 20;
                                    } else {
                                        $strout = strlen($output);
                                    }

                                    $output = 'U';
                                }
                            }

                            $return[$i]['value']         = $output;
                            $return[$i]['rrd_name']      = $item['rrd_name'];
                            $return[$i]['local_data_id'] = $local_data_id;

                            break;
                        case POLLER_ACTION_SCRIPT: /* script (popen) */
                            $output = trim(exec_poll($item['arg1']));

                            if (prepare_validate_result($output) === false) {
                                if (strlen($output) > 20) {
                                    $strout = 20;
                                } else {
                                    $strout = strlen($output);
                                }

                                $output = 'U';
                            }

                            $return[$i]['value']         = $output;
                            $return[$i]['rrd_name']      = $item['rrd_name'];
                            $return[$i]['local_data_id'] = $local_data_id;

                            break;
                        case POLLER_ACTION_SCRIPT_PHP: /* script (php script server) */
                            $cactides = array(
                                0 => array('pipe', 'r'), // stdin is a pipe that the child will read from
                                1 => array('pipe', 'w'), // stdout is a pipe that the child will write to
                                2 => array('pipe', 'w')  // stderr is a pipe to write to
                            );

                            if (function_exists('proc_open')) {
                                $php_bin  = cacti_escapeshellcmd(read_config_option('path_php_binary'));
                                $srv_path = cacti_escapeshellarg($config['base_path'] . '/script_server.php');
                                $cactiphp = proc_open($php_bin . ' -q ' . $srv_path . ' realtime ' . cacti_escapeshellarg($poller_id), $cactides, $pipes);
                                $output = fgets($pipes[1], 1024);
                                $using_proc_function = true;
                            } else {
                                $using_proc_function = false;
                            }

                            if ($using_proc_function == true) {
                                $output = trim(str_replace("\n", '', exec_poll_php($item['arg1'], $using_proc_function, $pipes, $cactiphp)));

                                if (prepare_validate_result($output) === false) {
                                    if (strlen($output) > 20) {
                                        $strout = 20;
                                    } else {
                                        $strout = strlen($output);
                                    }

                                    $output = 'U';
                                }
                            } else {
                                $output = 'U';
                            }

                            $return[$i]['value']         = $output;
                            $return[$i]['rrd_name']      = $item['rrd_name'];
                            $return[$i]['local_data_id'] = $local_data_id;

                            if (($using_proc_function == true) && ($script_server_calls > 0)) {
                                /* close php server process */
                                fwrite($pipes[0], "quit\r\n");
                                fclose($pipes[0]);
                                fclose($pipes[1]);
                                fclose($pipes[2]);

                                $return_value = proc_close($cactiphp);
                            }

                            break;
                    }

                    $i++;
                }
            }
        }
    }

    print json_encode($return);
}

function run_remote_data_query()
{
    $host_id = get_filter_request_var('host_id');
    $data_query_id = get_filter_request_var('data_query_id');

    if (remote_agent_host_belongs_to_authorized_poller($host_id) && $data_query_id > 0) {
        run_data_query($host_id, $data_query_id);
    }
}

/**
 * Check whether a device belongs to the poller authenticated by source address.
 *
 * @param int $host_id Device identifier.
 *
 * @return bool
 */
function remote_agent_host_belongs_to_authorized_poller($host_id)
{
    global $config, $remote_agent_authorized_poller_id;

    // Registered collectors can broker UI calls; targets must belong to this receiver.
    if ($remote_agent_authorized_poller_id <= 0 || $config['poller_id'] <= 0 || $host_id <= 0) {
        return false;
    }

    $host_poller_id = db_fetch_cell_prepared('SELECT poller_id FROM host WHERE id = ?', array($host_id));

    return (int) $host_poller_id === (int) $config['poller_id'];
}

/**
 * Require every device used by a graph to belong to the local collector.
 *
 * @param int $local_graph_id Graph identifier.
 *
 * @return bool
 */
function remote_agent_graph_belongs_to_authorized_poller($local_graph_id)
{
    global $config, $remote_agent_authorized_poller_id;

    if ($local_graph_id <= 0 || $remote_agent_authorized_poller_id <= 0 || $config['poller_id'] <= 0) {
        return false;
    }
    $owner = (int) ($config['poller_id'] > 1 ? $config['poller_id'] : $remote_agent_authorized_poller_id);
    $hosts = db_fetch_assoc_prepared('SELECT DISTINCT dl.host_id, h.poller_id
        FROM graph_templates_item AS gti
        LEFT JOIN data_template_rrd AS dtr ON dtr.id = gti.task_item_id
        LEFT JOIN data_local AS dl ON dl.id = dtr.local_data_id
        LEFT JOIN host AS h ON h.id = dl.host_id
        WHERE gti.local_graph_id = ? AND gti.task_item_id > 0', array($local_graph_id));
    if (!is_array($hosts)) {
        return false;
    }
    if (empty($hosts)) {
        $host = db_fetch_row_prepared('SELECT gl.host_id, h.poller_id
            FROM graph_local AS gl LEFT JOIN host AS h ON h.id = gl.host_id
            WHERE gl.id = ?', array($local_graph_id));
        $hosts = $host ? array($host) : array();
    }
    if (empty($hosts)) {
        return false;
    }
    foreach ($hosts as $host) {
        if (!isset($host['host_id'])) {
            return false;
        }
        // Non-device graphs have no collector ownership: only central storage
        // can serve them, after the explicit user permission check above.
        if ((int) $host['host_id'] === 0 && (int) $config['poller_id'] === 1) {
            continue;
        }
        if ((int) $host['host_id'] <= 0 || (int) $host['poller_id'] !== $owner) {
            return false;
        }
    }

    return true;
}

function run_remote_discovery()
{
    global $config;
    global $remote_agent_authorized_poller_id;

    if ($remote_agent_authorized_poller_id <= 0 || $config['poller_id'] <= 0) {
        return false;
    }

    $network_id = (int) get_filter_request_var('network');
    if ($network_id < 0 || ($network_id === 0 && $remote_agent_authorized_poller_id !== 1) || ($network_id > 0 && (int) db_fetch_cell_prepared('SELECT poller_id FROM automation_networks WHERE id = ?', array($network_id)) !== (int) $config['poller_id'])) {
        return false;
    }

    $poller_id = cacti_escapeshellarg($config['poller_id']);
    $network   = cacti_escapeshellarg(get_filter_request_var('network'));
    $php       = cacti_escapeshellcmd(read_config_option('path_php_binary'));
    $path      = cacti_escapeshellarg(read_config_option('path_webroot') . '/poller_automation.php');

    $options   = ' --poller=' . $poller_id . ' --network=' . $network . ' --force';
    if (isset_request_var('debug')) {
        $options .= ' --debug';
    }

    exec_background($php, '-q ' . $path . $options);

    sleep(2);

    return;
}
