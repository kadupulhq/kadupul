<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_0_8_2() {
	global $database_log;

	db_install_add_column('data_input_data_cache', array('name' => 'host_id', 'type' => 'mediumint(8)', 'NULL' => false, 'after' => 'local_data_id'));
	db_install_add_column('host', array('name' => 'disabled', 'type' => 'char(2)'));
	db_install_add_column('host', array('name' => 'status', 'type' => 'tinyint(2)', 'NULL' => false));

	db_install_execute("UPDATE host_snmp_cache set field_name='ifName' where field_name='ifAlias' and snmp_query_id=1;");
	db_install_execute("UPDATE snmp_query_graph_rrd_sv set text = REPLACE(text,'ifAlias','ifName') where (snmp_query_graph_id=1 or snmp_query_graph_id=13 or snmp_query_graph_id=14 or snmp_query_graph_id=16 or snmp_query_graph_id=9 or snmp_query_graph_id=2 or snmp_query_graph_id=3 or snmp_query_graph_id=4);");
	db_install_execute("UPDATE snmp_query_graph_sv set text = REPLACE(text,'ifAlias','ifName') where (snmp_query_graph_id=1 or snmp_query_graph_id=13 or snmp_query_graph_id=14 or snmp_query_graph_id=16 or snmp_query_graph_id=9 or snmp_query_graph_id=2 or snmp_query_graph_id=3 or snmp_query_graph_id=4);");
	db_install_execute("UPDATE host set disabled = '';");
}
