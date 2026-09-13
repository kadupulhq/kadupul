<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_2_31() {
	global $config;

	db_install_execute('ALTER TABLE automation_devices MODIFY COLUMN snmp_priv_protocol char(7) default ""');
	db_install_execute('ALTER TABLE automation_snmp_items MODIFY COLUMN snmp_priv_protocol char(7) default ""');
	db_install_execute('ALTER TABLE snmpagent_managers MODIFY COLUMN snmp_priv_protocol char(7) NOT NULL');

	db_install_execute('ALTER TABLE settings MODIFY COLUMN `name` varchar(255) NOT NULL default ""');

	if (!db_index_exists('snmp_query_graph', 'snmp_query_id')) {
		db_install_execute('ALTER TABLE snmp_query_graph ADD INDEX snmp_query_id (snmp_query_id)');
	}

	if (!db_index_exists('snmp_query_graph', 'graph_template_id')) {
		db_install_execute('ALTER TABLE snmp_query_graph ADD INDEX graph_template_id (graph_template_id)');
	}

	db_install_execute('ALTER TABLE settings_user MODIFY COLUMN name varchar(255) NOT NULL default ""');
}

