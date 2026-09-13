<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_2_26() {
	db_install_execute("ALTER TABLE `settings` MODIFY `value` varchar(4096) not null default ''");
	db_install_execute("ALTER TABLE `settings_user` MODIFY `value` varchar(4096) not null default ''");

	/* rerun this function just in case for some reason it was missed */
	$duplicates = db_install_fetch_assoc('SELECT hex, COUNT(*) AS totals
		FROM colors
		GROUP BY hex
		HAVING totals > 1');

	if (cacti_sizeof($duplicates['data'])) {
		$duplicates = $duplicates['data'];

		foreach($duplicates as $duplicate) {
			$hexes_results = db_install_fetch_assoc('SELECT id, hex
				FROM colors
				WHERE hex = ?
				ORDER BY id ASC',
				array($duplicate['hex']), true);
			$hexes = $hexes_results['data'];

			$first = true;

			foreach($hexes as $hex) {
				if ($first) {
					$keephex = $hex['id'];
					$first   = false;
				} else {
					db_install_execute('UPDATE graph_templates_item
						SET color_id = ?
						WHERE color_id = ?',
						array($keephex, $hex['id']));

					if (db_table_exists('color_template_items')) {
						db_install_execute('UPDATE color_template_items
							SET color_id = ?
							WHERE color_id = ?',
							array($keephex, $hex['id']));
					}

					db_install_execute('DELETE FROM colors WHERE id = ?', array($hex['id']));
				}
			}
		}

		if (!db_index_exists('colors', 'hex')) {
			db_install_add_key('colors', 'UNIQUE INDEX', 'hex', array('hex'));
		}
	}
}

