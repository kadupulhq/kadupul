<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_1_11() {
	db_install_execute('ALTER TABLE poller_data_template_field_mappings
		MODIFY COLUMN data_name VARCHAR(40) NOT NULL DEFAULT "",
		MODIFY COLUMN data_source_names VARCHAR(125) NOT NULL DEFAULT ""');

	// required for the new plugin structure
	db_install_execute('DELETE FROM settings WHERE name LIKE "md5%_plugins"');
	db_install_execute('DELETE FROM poller_resource_cache WHERE `path` LIKE "plugins/%"');
}
