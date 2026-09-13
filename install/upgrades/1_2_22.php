<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_2_22() {
	global $config;

	db_install_execute('ALTER TABLE host
		MODIFY COLUMN `deleted` CHAR(2) NOT NULL default "",
		MODIFY COLUMN `disabled` CHAR(2) NOT NULL default ""');
}

