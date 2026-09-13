#!/usr/bin/env php
<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/* do NOT run this script through a web browser */
if (php_sapi_name() != 'cli') {
	die('<br><strong>This script is only meant to run at the command line.</strong>');
}

if ($argv !== false && sizeof($argv)) {
	$value = intval($argv[1]);
	print $value * $value;
}
