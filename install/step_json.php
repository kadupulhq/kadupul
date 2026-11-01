<?php

declare(strict_types=1);
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/* since we'll have additional headers, tell php when to flush them */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	header('Allow: POST');
	http_response_code(405);
	exit;
}

ob_start();

// Reject malformed tokens before the authentication bootstrap invokes CSRF.
if (!is_string($_POST['__csrf_magic'] ?? null)) {
	http_response_code(403);
	exit;
}

// Prevent redirect to /install/
define('IN_CACTI_INSTALL', 1);
chdir(__DIR__ . '/../');

/* set the json variable for request validation handling */
include_once('lib/functions.php');
include_once('lib/html_utility.php');
set_request_var('json', true);
$auth_json = true;

include('include/auth.php');
if (!is_string($_POST['__csrf_magic'] ?? null) || !csrf_check(false)) {
	http_response_code(403);
	exit;
}

// Old browser tabs must not reach the previous polling/start write path.
// Keep the established JSON error envelope while directing them to the review UI.
http_response_code(409);
header('Content-Type: application/json');
header('Cache-Control: private, no-store');
print json_encode(array(
    'status' => '409',
    'statusText' => 'Installer moved',
    'responseText' => 'Reload install.php to review and run the current installation plan.',
), JSON_THROW_ON_ERROR);
