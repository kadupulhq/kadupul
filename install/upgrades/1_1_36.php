<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_1_36() {
	// Repair locales
	$def_locale = repair_locale(read_config_option('i18n_default_language'));
	set_config_option('i18n_default_language', $def_locale);

	$users_to_update_results = db_install_fetch_assoc('SELECT *
		FROM settings_user
		WHERE name="user_language"');
	$users_to_update = $users_to_update_results['data'];

	if (cacti_sizeof($users_to_update)) {
		foreach($users_to_update as $user) {
			if (strpos($user['value'], '-') === false) {
				$locale = repair_locale($user['value']);

				db_install_execute('UPDATE settings_user
					SET value = ?
					WHERE user_id = ?
					AND name = ?',
					array($locale, $user['user_id'], $user['name']));
			}
		}
	}

	$groups_to_update_results = db_install_fetch_assoc('SELECT *
		FROM settings_user_group
		WHERE name="user_language"');
	$groups_to_update = $groups_to_update_results['data'];

	if (cacti_sizeof($groups_to_update)) {
		foreach($groups_to_update as $group) {
			if (strpos($group['value'], '-') === false) {
				$locale = repair_locale($group['value']);

				db_install_execute('UPDATE settings_user_group
					SET value = ?
					WHERE group_id = ?
					AND name = ?',
					array($locale, $group['group_id'], $group['name']));
			}
		}
	}
}

