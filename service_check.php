<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

include('./include/global.php');

$result = db_fetch_cell('SELECT cacti FROM version');

if ($result != '') {
	print 'success' . PHP_EOL;
} else {
	print 'fail' . PHP_EOL;
}

