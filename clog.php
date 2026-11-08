<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

$guest_account=true;

include('./include/auth.php');
include_once('./lib/clog_webapi.php');
include_once('./lib/poller.php');
include_once('./lib/utility.php');

/* check edit/alter permissions */
if (!clog_admin()) {
	if (isset_request_var('header')) {
		if ($config['poller_id'] > 1) {
			print '<div style="display:none">cactiRemoteState</div>';
		} else {
			print '<div style="display:none">cactiPermissionDenied</div>';
		}
	} elseif ($config['poller_id'] > 1) {
		// The session is held here, so end it rather than ask for confirmation.
		auth_end_session('remote');
		header('Location: logout.php', true, 303);
	} else {
		header('Location: permission_denied.php');
	}

	exit;
}

load_current_session_value('page_referrer', 'page_referrer', '');

clog_view_logfile();
