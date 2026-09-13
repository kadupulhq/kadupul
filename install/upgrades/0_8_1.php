<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_0_8_1() {
	db_install_add_column('user_log', array('name' => 'user_id', 'type' => 'mediumint(8)', 'NULL' => false, 'after' => 'username'));
	db_install_add_column('user_auth', array('name' => 'realm', 'type' => 'mediumint(8)', 'NULL' => false, 'after' => 'password'));

	db_install_execute("ALTER TABLE user_log change time time datetime not null;");

	db_install_drop_key('user_log', '', 'PRIMARY KEY');
	db_install_add_key('user_log', '', 'PRIMARY KEY', array('username', 'user_id', 'time'));

	db_install_execute("UPDATE user_auth set realm = 1 where full_name='ldap user';");

	$_src_results = db_install_fetch_assoc("select id, username from user_auth");
	$_src         = $_src_results['data'];

	if (cacti_sizeof($_src) > 0) {
		foreach ($_src as $item) {
			db_install_execute("UPDATE user_log
				SET user_id = ?
				WHERE username = ?",
				array($item["id"], $item["username"]));
		}
	}
}
