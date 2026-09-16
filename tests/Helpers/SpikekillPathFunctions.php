<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

// Execute the production pure path helpers without loading database globals.
require_once __DIR__ . '/PhpSource.php';
$source = file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php');
foreach (array('cacti_trim_dir_separator', 'cacti_join_dir_child', 'cacti_path_is_within', 'cacti_normalize_windows_path') as $name) {
	if (!function_exists($name)) {
		eval(test_php_function_source($source, $name)); // nosemgrep: php.lang.security.eval-use.eval-use
	}
}
