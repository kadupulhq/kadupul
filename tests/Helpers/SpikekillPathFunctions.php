<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

// Execute the production pure path helper without loading database globals.
foreach (array('cacti_trim_dir_separator', 'cacti_join_dir_child', 'cacti_path_is_within', 'cacti_normalize_windows_path') as $name) {
	if (function_exists($name)) {
		continue;
	}
	$source = file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php');
	$start = strpos($source, 'function ' . $name . '(');
	if ($start === false) {
		throw new RuntimeException('Missing production directory join helper');
	}
	$end = strpos($source, "\n}\n", $start);
	if ($end === false) {
		throw new RuntimeException('Incomplete production directory join helper');
	}
	eval(substr($source, $start, $end - $start + 2)); // nosemgrep: php.lang.security.eval-use.eval-use
}
