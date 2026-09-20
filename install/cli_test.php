#!/usr/bin/env php
<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/* do NOT run this script through a web browser */
if (php_sapi_name() != 'cli') {
	die('<br><strong>This script is only meant to run at the command line.</strong>');
}

if ($argv !== false && sizeof($argv)) {
	$value = intval($argv[1]);
	// The main installer uses a bounded, portable exit-status channel. Keep
	// legacy stdout behavior for callers that do not explicitly opt in.
	if (($argv[2] ?? '') === '--exit-status') {
		exit(($value * $value) % 251 + 1);
	}
	print $value * $value;
}
