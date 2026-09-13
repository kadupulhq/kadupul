<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_2_23() {
	db_install_execute("CREATE TABLE IF NOT EXISTS `rrdcheck` (
		`local_data_id` mediumint(8) unsigned NOT NULL,
		`test_date` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
		`message` varchar(250) default '')
		 ENGINE=InnoDB ROW_FORMAT=Dynamic
		 COMMENT='Store result of RRDcheck'");

	if (!db_column_exists('host_template', 'class')) {
		db_install_execute('ALTER TABLE host_template ADD COLUMN class varchar(40) NOT NULL default "" AFTER name');
	}

	db_install_execute("CREATE TABLE IF NOT EXISTS `user_auth_row_cache` (
		`user_id` mediumint(8) NOT NULL DEFAULT 0,
		`class` varchar(20) NOT NULL DEFAULT '',
		`hash` varchar(32) NOT NULL DEFAULT '0',
		`total_rows` int(10) unsigned NOT NULL DEFAULT 0,
		`time` timestamp NOT NULL DEFAULT current_timestamp(),
		PRIMARY KEY (`user_id`,`class`,`hash`))
		ENGINE=InnoDB
		ROW_FORMAT=DYNAMIC");
}

