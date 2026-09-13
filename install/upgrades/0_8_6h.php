<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_0_8_6h() {
	/* changes for ping result times */
	db_install_execute("ALTER TABLE `host` MODIFY COLUMN `min_time` DECIMAL(10,5) DEFAULT 9.99999;");
	db_install_execute("ALTER TABLE `host` MODIFY COLUMN `max_time` DECIMAL(10,5) DEFAULT 0.00000;");
	db_install_execute("ALTER TABLE `host` MODIFY COLUMN `cur_time` DECIMAL(10,5) DEFAULT 0.00000;");
	db_install_execute("ALTER TABLE `host` MODIFY COLUMN `avg_time` DECIMAL(10,5) DEFAULT 0.00000;");

	/* Changes to user_log */
	db_install_execute("ALTER TABLE `user_log` MODIFY COLUMN `ip` VARCHAR(40);");

	/* Fixes broken graphs that have graph items with legend text but no color assigned */
	db_install_execute("UPDATE graph_templates_item SET text_format = '' WHERE local_graph_id <> 0 AND color_id = 0 AND graph_type_id IN(4,5,6,7,8) AND text_format <> '';");
}
