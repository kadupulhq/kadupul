#!/usr/bin/env php
<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

global $original_memory_limit;

$original_memory_limit = ini_get('memory_limit');
ini_set('memory_limit','-1');

include(dirname(__FILE__) . '/../include/cli_check.php');
include(dirname(__FILE__) . '/../lib/utility.php');

if ($argv !== false && $argc != false && $argc > 1) {
	$value = strtolower($argv[1]);

	if ($value == 'extensions') {
		$ext = false;
		utility_php_verify_extensions($ext,'cli');
		print json_encode($ext);
	} else if ($value == 'recommends') {
		$rec = false;
		utility_php_verify_recommends($rec, 'cli');
		print json_encode($rec);
	} else if ($value == 'optionals') {
		$opt = false;
		utility_php_verify_optionals($opt, 'cli');
		print json_encode($opt);
	}
}
