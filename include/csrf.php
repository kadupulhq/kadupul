<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

// Kadupul submits one serialized token string, never PHP parameter arrays.
// Reject malformed input before csrf-magic iterates nested attacker input.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && array_key_exists('__csrf_magic', $_POST) && !is_string($_POST['__csrf_magic'])) {
    http_response_code(403);
    exit;
}

require_once($config['include_path'] . '/vendor/csrf/csrf-conf.php');

/* cross site request forgery library */
function csrf_startup()
{
    global $config;

    if ($config['is_web']) {
        /* If you need to debug CSRF, uncomment the following line */
        //csrf_conf('log_file', dirname(read_config_option('path_cactilog')) . '/csrf.log');
        if (!empty($config['path_csrf_secret'])) {
            csrf_conf('path_secret', $config['path_csrf_secret']);
        }

        csrf_conf('rewrite-js', $config['url_path'] . 'include/vendor/csrf/csrf-magic.js');
        csrf_conf('callback', 'csrf_error_callback');
        csrf_conf('expires', 7200);
    } else {
        csrf_conf('disable', true);
    }
}

function csrf_error_callback()
{
    //Resolve session fixation for PHP 5.4
    session_regenerate_id();
    raise_message('csrf_timeout');
    ob_end_clean();
    header('Location: ' . validate_redirect_url($_SERVER['REQUEST_URI']));
    csrf_log(__FUNCTION__, 'Timeout, redirecting to ' . validate_redirect_url($_SERVER['REQUEST_URI']));
    exit;
}

/**
 * Refuse a state-changing action that arrives by a method other than POST,
 * except a GET the browser does not mark as coming from another site.
 *
 * csrf-magic validates the token before page dispatch for every POST. These
 * actions change data, and the pages and plugins that use them still send
 * them as GET links or same-origin XHR, so a same-site GET stays allowed.
 * Actions a page lists in cacti_require_post_actions() stay POST-only there.
 */
function csrf_refuse_cross_site_actions()
{
    static $actions = array(
        'delete_node', 'gt_remove', 'query_remove', 'remove', 'change_leaf',
        'create_node', 'rename_node', 'move_node', 'copy_node',
        'item_remove', 'item_moveup', 'item_movedown',
        'item_remove_gsv', 'item_remove_dssv',
        'item_moveup_gsv', 'item_moveup_dssv',
        'item_movedown_gsv', 'item_movedown_dssv',
        'moveup', 'movedown',
        'tree_up', 'tree_down', 'move_page_up', 'move_page_down', 'delete_page',
        'rrd_add', 'rrd_remove',
        'logout_everywhere', 'clear_user_settings', 'reset_default',
        'ajax_dnd', 'lock', 'unlock', 'sortasc', 'sortdesc', 'set_branch_sort', 'set_host_sort',
        'ajax_reports', 'update_timespan',
        'run_debug', 'run_repair', 'runall', 'ds_disable', 'ds_enable',
        'query_reload', 'ajax_save', 'ajax_save_filter',
        'reindex', 'gt_add', 'query_add', 'query_change', 'query_verbose',
        'ping_host', 'enable_debug', 'disable_debug', 'repopulate',
        'item_add_gt', 'item_remove_gt', 'item_add_dq', 'item_remove_dq',
        'ping', 'restart', 'remall', 'arcall', 'send_test', 'perm_remove',
        'clear_poller_cache', 'rebuild_resource_cache', 'clear_logfile', 'purge_logfile', 'clear_user_log',
        'field_remove', 'ds_remove', 'template_remove', 'input_remove', 'send',
        'purge_data_source_statistics', 'rebuild_snmpagent_cache',
    );

    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    $action = $_REQUEST['action'] ?? '';

    if ($method === 'POST' || !is_string($action)) {
        return;
    }

    // Breadcrumbs link back to a confirmation page by GET, so 'actions' only
    // changes data once selected_items arrives.
    if (!in_array($action, $actions, true) && ($action !== 'actions' || !isset($_REQUEST['selected_items']))) {
        return;
    }

    if ($method === 'GET' && !csrf_request_is_cross_site()) {
        return;
    }

    header('Allow: POST');
    http_response_code(405);
    exit;
}

/**
 * Whether the browser marked this request as coming from another site.
 *
 * Sec-Fetch-Site decides whenever a browser sends it, because a page can not
 * set it. Browsers without it fall back to the Origin and Referer hosts. A
 * request with none of these, such as a bookmark, a script or a Remote Data
 * Collector call, is not cross-site.
 */
function csrf_request_is_cross_site()
{
    if (isset($_SERVER['HTTP_SEC_FETCH_SITE'])) {
        return !in_array(strtolower(trim($_SERVER['HTTP_SEC_FETCH_SITE'])), array('same-origin', 'none'), true);
    }

    foreach (array('HTTP_ORIGIN', 'HTTP_REFERER') as $header) {
        if (isset($_SERVER[$header]) && $_SERVER[$header] !== '' && !csrf_request_host_matches($_SERVER[$header])) {
            return true;
        }
    }

    return false;
}

/**
 * Whether a URL names this server.
 *
 * The host is compared with the server-configured name, as
 * validate_redirect_url() does, because the Host header is client input.
 * Only the host name is compared: TLS ending at a reverse proxy changes the
 * scheme and often the port, and a browser that tells ports apart sends
 * Sec-Fetch-Site, so it never reaches this comparison.
 */
function csrf_request_host_matches($url)
{
    $source = parse_url($url, PHP_URL_HOST);
    $target = isset($_SERVER['SERVER_NAME']) ? csrf_strip_host_port($_SERVER['SERVER_NAME']) : '';

    if (!is_string($source) || $source === '' || $target === '') {
        return false;
    }

    return strtolower(trim($source, '[]')) === strtolower(trim($target, '[]'));
}

/**
 * Strip a trailing :port from a host, the way parse_url() reports it.
 *
 * A bracketed IPv6 host keeps its brackets, with or without a port. A bare
 * IPv6 address, as Apache and nginx report SERVER_NAME when the Host header
 * carries no port, has more than one colon and none of them separates a
 * port, so it is returned unchanged.
 */
function csrf_strip_host_port($host)
{
    if (preg_match('/^(\[[0-9A-Fa-f:]+\])(?::\d+)?$/', $host, $matches)) {
        return $matches[1];
    }

    if (substr_count($host, ':') > 1) {
        return $host;
    }

    return preg_replace('/:\d+$/', '', $host);
}

include_once($config['include_path'] . '/vendor/csrf/csrf-magic.php');
