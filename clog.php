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
		if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' || csrf_request_is_same_origin()) {
			// A POST passed CSRF and a same-site GET came from this application,
			// so end the session here rather than ask for confirmation.
			auth_end_session();
			header('Location: logout.php?action=remote', true, 303);
		} else {
			// Another site may have sent this GET: only offer the CSRF-bound logout.
			header('Location: logout.php?action=remote');
		}
	} else {
		header('Location: permission_denied.php');
	}

	exit;
}

load_current_session_value('page_referrer', 'page_referrer', '');

clog_view_logfile();
