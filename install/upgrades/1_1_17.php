<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_1_17() {
	// Finalize fix to LDAP authentication
	db_install_execute('UPDATE user_auth SET realm=3 WHERE realm=1');

	if (!db_column_exists('data_source_profiles_rra', 'timespan')) {
		db_install_execute('ALTER TABLE data_source_profiles_rra
			ADD COLUMN timespan int(10) unsigned NOT NULL DEFAULT "0"');

		$rras_results = db_install_fetch_assoc("SELECT * FROM data_source_profiles_rra");
		$rras         = $rras_results['data'];

		if (cacti_sizeof($rras)) {
			foreach($rras as $rra) {
				$interval_results = db_install_fetch_cell('SELECT step
					FROM data_source_profiles
					WHERE id = ?',
					array($rra['data_source_profile_id']));
				$interval = $interval_results['data'];

				$timespan = $rra['steps'] * $interval * $rra['rows'];

				$timespan = get_nearest_timespan($timespan);

				db_install_execute('UPDATE data_source_profiles_rra
					SET timespan = ?
					WHERE id = ?',
					array($timespan, $rra['id']));
			}
		}
	}
}
