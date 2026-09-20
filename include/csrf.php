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

require_once($config['include_path'] .'/vendor/csrf/csrf-conf.php');

/* cross site request forgery library */
function csrf_startup() {
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
		csrf_conf('disable',true);
	}
}

function csrf_error_callback() {
	//Resolve session fixation for PHP 5.4
	session_regenerate_id();
	raise_message('csrf_timeout');
	ob_end_clean();
	header('Location: ' . validate_redirect_url($_SERVER['REQUEST_URI']));
	csrf_log(__FUNCTION__, 'Timeout, redirecting to ' . validate_redirect_url($_SERVER['REQUEST_URI']));
	exit;
}

include_once($config['include_path'] . '/vendor/csrf/csrf-magic.php');
