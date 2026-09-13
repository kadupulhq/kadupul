<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/* reject non-CLI invocations early */
if (php_sapi_name() !== 'cli') {
	http_response_code(404);
	exit;
}

/* do NOT run this script through a web browser */
define('CACTI_CLI_ONLY', true);

/* We are not talking to the browser */
$no_http_headers = true;

/* Make sure CLI's are have minimum settings */
$default_limit  = -1;
$default_time   = -1;
$memory_limit   = ini_get('memory_limit');
$execution_time = ini_get('max_execution_time');

if ($memory_limit != $default_limit) {
	ini_set('memory_limit', $default_limit);
}

if ($execution_time < $default_time && $execution_time >= 0) {
	ini_set('max_execution_time', $default_time);
}

include(__DIR__ . '/global.php');

