<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_1_8() {
	// update graph_watermark if set
	$watermark = '';
	$result    = db_install_fetch_cell("SELECT `value` FROM `settings` WHERE name = 'graph_watermark'");

	if (isset($result['data'])) {
		$watermark = $result['data'];
	}

	if ($watermark == 'Copyright (C) 2004-2026 The Cacti Group') {
		db_install_execute("DELETE FROM `settings` WHERE name = 'graph_watermark'");
	}
}
