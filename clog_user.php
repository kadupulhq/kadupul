<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

$guest_account = true;
include('./include/auth.php');
include_once('./lib/clog_webapi.php');
include_once('./lib/poller.php');
include_once('./lib/utility.php');

/* check edit/alter permissions */
if (!clog_authorized()) {
	if (isset_request_var('header')) {
		if ($config['poller_id'] > 1) {
			print '<div style="display:none">cactiRemoteState</div>';
		} else {
			print '<div style="display:none">cactiPermissionDenied</div>';
		}
	} elseif ($config['poller_id'] > 1) {
		header('Location: logout.php?action=remote');
	} else {
		header('Location: permission_denied.php');
	}

	exit;
}

load_current_session_value('page_referrer', 'page_referrer', '');

clog_view_logfile();
