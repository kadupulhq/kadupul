<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

// include global
require(__DIR__ . '/../include/global.php');

// determine if we have html documentation otherwise send to GitHub Documentation Repo
if (file_exists($config['base_path'] . "/" . CACTI_DOCUMENTATION_TOC)) {
	// slurp up TOC and output
	print file_get_contents($config['base_path'] . "/" . CACTI_DOCUMENTATION_TOC);
} else {
	// Redirect to GitHub documentation
	header("Location: https://kadupul.org/");
}
