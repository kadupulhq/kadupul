<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Whether a posted token string has the shape csrf-magic issues.
 *
 * csrf-magic adds the time after a token's first comma to the expiry without
 * checking it is a number, and PHP 8 throws a TypeError for a string there,
 * so a time that is not all digits is refused before csrf-magic reads it.
 */
function csrf_token_is_well_formed($tokens)
{
    if (!is_string($tokens)) {
        return false;
    }

    foreach (explode(';', $tokens) as $token) {
        $value = explode(':', $token, 2)[1] ?? '';

        if (strpos($value, ',') === false) {
            continue;
        }

        if (!ctype_digit(explode(',', $value, 2)[1]) || !ctype_digit(explode(',', $token, 2)[1])) {
            return false;
        }
    }

    return true;
}

// Kadupul submits one serialized token string, never PHP parameter arrays.
// Reject malformed input before csrf-magic iterates nested attacker input.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && array_key_exists('__csrf_magic', $_POST) && !csrf_token_is_well_formed($_POST['__csrf_magic'])) {
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

        // Handing csrf-magic the secret keeps it from generating one and
        // writing it to include/vendor/csrf/csrf-secret.php, under the
        // document root, where a server without the include/ deny serves it.
        $secret = cacti_csrf_load_secret();

        if (!cacti_csrf_secret_is_valid($secret)) {
            http_response_code(500);
            die('ERROR: The configured external Kadupul CSRF secret is unavailable or invalid.');
        }

        csrf_conf('secret', $secret);

        // An ip: token is tied to no session, so another client behind the
        // same address could replay it; every page carries a sid: token.
        csrf_conf('allow-ip', false);
        csrf_conf('rewrite-js', $config['url_path'] . 'include/vendor/csrf/csrf-magic.js');
        csrf_conf('callback', 'csrf_error_callback');
        csrf_conf('expires', 7200);
    } else {
        csrf_conf('disable', true);
    }
}

/**
 * Return the CSRF secret: the packager-managed file named by
 * $path_csrf_secret when it is usable, otherwise the one stored in the
 * database, created on first use.
 *
 * During an install or upgrade the database may not hold settings yet, so a
 * per-session secret bridges the installer's own pages.
 */
function cacti_csrf_load_secret()
{
    global $config;

    $secret = '';
    $external = !empty($config['path_csrf_secret']);

    if ($external) {
        $secret = cacti_csrf_read_external_secret($config['path_csrf_secret']);
    }

    if (!cacti_csrf_secret_is_valid($secret) && !cacti_csrf_install_pending()) {
        $secret = read_config_option('csrf_secret', true);

        // An anonymous visitor has no stored session, so a per-session secret
        // would differ between the login form and its POST. Store one instead.
        if (!cacti_csrf_secret_is_valid($secret)) {
            set_config_option('csrf_secret', bin2hex(random_bytes(32)));
            $secret = read_config_option('csrf_secret', true);
        }

        // The session flag is lost the same way, so a settings marker also
        // limits the warning to one an hour.
        if ($external && empty($_SESSION['cacti_csrf_external_secret_warned']) && (int) read_config_option('csrf_external_secret_warned', true) < time() - 3600) {
            cacti_log('WARNING: The configured external CSRF secret is unavailable, invalid or under the document root, using ' . (cacti_csrf_secret_is_valid($secret) ? 'the database secret' : 'the session bootstrap secret') . ' instead', false, 'SYSTEM');
            $_SESSION['cacti_csrf_external_secret_warned'] = true;
            set_config_option('csrf_external_secret_warned', time());
        }
    }

    if (!cacti_csrf_secret_is_valid($secret)) {
        if (empty($_SESSION['cacti_bootstrap_csrf_secret'])) {
            $_SESSION['cacti_bootstrap_csrf_secret'] = bin2hex(random_bytes(32));
        }

        $secret = $_SESSION['cacti_bootstrap_csrf_secret'];
    }

    return $secret;
}

function cacti_csrf_install_pending()
{
    global $config;

    return defined('IN_CACTI_INSTALL')
        || (defined('CACTI_VERSION') && isset($config['cacti_db_version']) && $config['cacti_db_version'] !== CACTI_VERSION);
}

function cacti_csrf_secret_is_valid($secret)
{
    return is_string($secret) && strlen($secret) >= 32 && strlen($secret) <= 4096;
}

/**
 * Read a packager-managed CSRF secret from outside the document root.
 */
function cacti_csrf_read_external_secret($path)
{
    $path = cacti_csrf_external_secret_path($path);

    if (!cacti_csrf_external_path_is_safe($path) || !is_file($path)) {
        return '';
    }

    $secret = @file_get_contents($path, false, null, 0, 4097);

    if (!is_string($secret)) {
        return '';
    }

    $secret = cacti_csrf_parse_secret_contents($secret);

    return cacti_csrf_secret_is_valid($secret) ? $secret : '';
}

/**
 * Accept a raw secret, as the installer writes it, and the PHP wrapper older
 * refresh_csrf.php versions wrote.
 */
function cacti_csrf_parse_secret_contents($secret)
{
    $secret = trim($secret);

    if (preg_match('/^<\?php\s+\$secret\s*=\s*[\'"]([a-f0-9]{32,})[\'"]\s*;?\s*$/i', $secret, $matches)) {
        return $matches[1];
    }

    // Never use an unparsable PHP wrapper as the secret itself.
    if (strpos($secret, '<?') === 0) {
        return '';
    }

    return $secret;
}

/**
 * Resolve $path_csrf_secret, which may name a directory as the installer
 * has always allowed.
 */
function cacti_csrf_external_secret_path($path)
{
    if (!is_string($path) || $path === '') {
        return $path;
    }

    if (is_dir($path)) {
        $directory = realpath($path);
        $filename = 'csrf-secret.php';
    } else {
        $directory = realpath(dirname($path));
        $filename = basename($path);
    }

    return $directory === false ? $path : $directory . DIRECTORY_SEPARATOR . $filename;
}

/**
 * Whether an external secret resolves to an existing directory outside the
 * document root.
 */
function cacti_csrf_external_path_is_safe($path)
{
    global $config;

    $path = cacti_csrf_external_secret_path($path);

    if (!is_string($path) || $path === '') {
        return false;
    }

    $base_path = realpath($config['base_path']);
    $secret_dir = realpath(dirname($path));

    if ($base_path === false || $secret_dir === false) {
        return false;
    }

    $base_prefix = rtrim(str_replace('\\', '/', $base_path), '/') . '/';
    $secret_prefix = rtrim(str_replace('\\', '/', $secret_dir), '/') . '/';

    if (stripos($secret_prefix, $base_prefix) === 0) {
        return false;
    }

    if (file_exists($path)) {
        $secret_path = realpath($path);

        if ($secret_path === false || stripos(str_replace('\\', '/', $secret_path), $base_prefix) === 0) {
            return false;
        }
    }

    return true;
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
        'purge', 'qedit',
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
