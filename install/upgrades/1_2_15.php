<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_2_15() {
	db_install_execute('ALTER TABLE plugin_config MODIFY COLUMN `version` VARCHAR(10) NOT NULL default ""');
}
