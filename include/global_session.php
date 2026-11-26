<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

global $config, $refresh, $messages;

if (isset($_SESSION['automation_message']) && $_SESSION['automation_message'] != '') {
    $messages['automation_message'] = array(
        'message' => $_SESSION['automation_message'],
        'type' => 'info'
    );
    kill_session_var('automation_message');
}

if (isset($_SESSION['clog_message']) && $_SESSION['clog_message'] != '') {
    $messages['clog_message'] = array(
        'message' => $_SESSION['clog_message'],
        'type' => 'info'
    );
    kill_session_var('clog_message');
}

if (isset($_SESSION['clog_error']) && $_SESSION['clog_error'] != '') {
    $messages['clog_error'] = array(
        'message' => $_SESSION['clog_error'],
        'type' => 'error'
    );
    kill_session_var('clog_error');
}

$script = basename($_SERVER['SCRIPT_NAME']);
if ($script == 'graph_view.php' || $script == 'graph.php') {
    if (isset($_SESSION['custom']) && $_SESSION['custom'] == true) {
        $refreshIsLogout = 'true';
    } elseif (isset_request_var('action') && get_nfilter_request_var('action') == 'zoom') {
        $refreshIsLogout = 'true';
    } else {
        $refresh = api_plugin_hook_function('top_graph_refresh', read_user_setting('page_refresh'));
        $refreshIsLogout = 'false';
    }
} elseif (strstr($_SERVER['SCRIPT_NAME'], 'plugins')) {
    $refresh = api_plugin_hook_function('top_graph_refresh', $refresh);
    if (empty($refresh)) {
        $refreshIsLogout = 'true';
    } else {
        $refreshIsLogout = 'false';
    }
}

if (isset($_SESSION['refresh'])) {
    if (isset($_SESSION['refresh']['seconds'])) {
        $myrefresh['seconds'] = $_SESSION['refresh']['seconds'];
    } else {
        $myrefresh['seconds'] = ini_get('session.gc_maxlifetime');
    }

    if (isset($_SESSION['refresh']['logout'])) {
        $refreshIsLogout = $_SESSION['refresh']['logout'];
    } else {
        $refreshIsLogout = 'false';
    }

    if (isset($_SESSION['refresh']['page'])) {
        $myrefresh['page'] = sanitize_uri($_SESSION['refresh']['page'], false);
    } else {
        $myrefresh['page'] = $config['url_path'] . 'logout.php?action=timeout';
        $refreshIsLogout   = 'true';
    }

    unset($_SESSION['refresh']);
} elseif (isset($refresh) && is_array($refresh)) {
    $myrefresh['seconds'] = $refresh['seconds'];
    $myrefresh['page']    = sanitize_uri(appendHeaderSuppression($refresh['page']), false);
    $refreshIsLogout      = 'false';
} elseif (isset($refresh)) {
    $myrefresh['seconds'] = $refresh;
    $myrefresh['page']    = sanitize_uri(appendHeaderSuppression($_SERVER['REQUEST_URI'] ?? ''));
    $refreshIsLogout      = 'false';
} elseif (read_config_option('auth_cache_enabled') == 'on' && isset($_SESSION['cacti_remembers']) && $_SESSION['cacti_remembers'] == true) {
    $myrefresh['seconds'] = 99999999;
    $myrefresh['page']    = sanitize_uri($_SERVER['REQUEST_URI'] ?? '');
    $refreshIsLogout      = 'false';
} elseif (read_user_setting('user_auto_logout_time') > 0 && is_realm_allowed(8)) {
    $myrefresh['seconds'] = read_user_setting('user_auto_logout_time');
    $myrefresh['page']    = $config['url_path'] . 'logout.php?action=timeout';
    $refreshIsLogout      = 'true';
} elseif (read_config_option('auth_method') == 2) {
    $myrefresh['seconds'] = 99999999;
    $myrefresh['page']    = 'index.php';
    $refreshIsLogout      = 'false';
} elseif (!isset($_SESSION['sess_user_id']) && ($script === 'index.php' || $script === 'login.php')) {
    $myrefresh['seconds'] = 99999999;
    $myrefresh['page']    = sanitize_uri($_SERVER['REQUEST_URI'] ?? '');
    $refreshIsLogout      = 'false';
} else {
    $myrefresh['seconds'] = ini_get('session.gc_maxlifetime');
    $myrefresh['page']    = $config['url_path'] . 'logout.php?action=timeout';
    $refreshIsLogout      = 'true';
}

/* guest account does not auto log off */
if (isset($_SESSION['sess_user_id']) && $_SESSION['sess_user_id'] == read_config_option('guest_user')) {
    $myrefresh['seconds'] = 99999999;
    $myrefresh['page']    = sanitize_uri($_SERVER['REQUEST_URI'] ?? '');
    $refreshIsLogout      = 'false';
}

/* basic auth times out when the auth provider times out */
if (read_config_option('auth_method') == 2) {
    $myrefresh['seconds'] = 99999999;
    $myrefresh['page']    = sanitize_uri($_SERVER['REQUEST_URI'] ?? '');
    $refreshIsLogout = 'false';
}

?>
<script type='text/javascript' <?php print CactiSecureHeaders::getNonceAttribute();?>>
	var cactiVersion=<?php print json_encode((string) $config['cacti_version'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);?>;
	var cactiNonce=<?php print json_encode((string) CactiSecureHeaders::getNonce(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);?>;
	var cactiServerOS=<?php print json_encode((string) $config['cacti_server_os'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);?>;
	var cactiAction=<?php print json_encode((string) get_filter_request_var('action', FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => '/\A[a-zA-Z0-9_ -]+\z/D'))), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);?>;
	var theme=<?php print json_encode((string) get_selected_theme(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);?>;
	var refreshIsLogout=<?php print $refreshIsLogout;?>;
	var refreshPage='<?php print html_escape($myrefresh['page']);?>';
	var refreshMSeconds=<?php print (int) $myrefresh['seconds'] * 1000;?>;
	var urlPath=<?php print json_encode((string) $config['url_path'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);?>;
	var previousPage='';
	var sessionMessage=<?php print display_output_messages(false);?>;
	var csrfMagicToken=<?php print json_encode((string) csrf_get_tokens(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);?>;
</script>
