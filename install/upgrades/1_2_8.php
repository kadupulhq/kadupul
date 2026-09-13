<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_2_8() {
	db_install_execute('ALTER TABLE host_snmp_cache ROW_FORMAT=Dynamic, DROP INDEX snmp_index, DROP INDEX field_value');
	db_install_execute('ALTER TABLE host_snmp_cache MODIFY COLUMN snmp_index VARCHAR(255) NOT NULL default ""');
	db_install_execute('ALTER TABLE host_snmp_cache ADD INDEX snmp_index(snmp_index), ADD INDEX field_value(field_value)');
	db_install_execute('ALTER TABLE data_local ROW_FORMAT=Dynamic, DROP INDEX snmp_index, ADD INDEX snmp_index(snmp_index)');
	db_install_execute('ALTER TABLE graph_local ROW_FORMAT=Dynamic, DROP INDEX snmp_index, ADD INDEX snmp_index(snmp_index)');

	// Needed to fix aggregate bug
	if (!db_column_exists('aggregate_graphs', 'gprint_format')) {
		db_install_execute('ALTER TABLE aggregate_graphs ADD COLUMN gprint_format CHAR(2) default "" AFTER gprint_prefix');
	}

	if (!db_column_exists('aggregate_graph_templates', 'gprint_format')) {
		db_install_execute('ALTER TABLE aggregate_graph_templates ADD COLUMN gprint_format CHAR(2) default "" AFTER gprint_prefix');
	}

	// Reimport colors
	import_colors();
}
