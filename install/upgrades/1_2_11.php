<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_2_11() {
	db_install_execute("CREATE TABLE IF NOT EXISTS `processes` (
		`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		`pid` int(10) unsigned NOT NULL DEFAULT 0,
		`tasktype` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
		`taskname` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
		`taskid` int(10) unsigned NOT NULL DEFAULT 0,
		`timeout` int(11) DEFAULT 300,
		`started` timestamp NOT NULL DEFAULT current_timestamp(),
		`last_update` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY (`pid`,`tasktype`,`taskname`,`taskid`),
		KEY `tasktype` (`tasktype`),
		KEY `pid` (`pid`),
		KEY `id` (`id`))
		ENGINE=MEMORY
		COMMENT='Stores Process Status for Kadupul Background Processes'");
}

