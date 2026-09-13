<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_2_21() {
	global $config;

	db_install_drop_key('data_source_profiles_cf', 'index', 'data_source_profile_id');
	db_install_drop_key('data_template_rrd', 'index', 'local_data_id');
	db_install_drop_key('graph_template_input_defs', 'index', 'graph_template_input_id');
	db_install_drop_key('host', 'index', 'site_id');
	db_install_drop_key('host_snmp_query', 'index', 'host_id');
	db_install_drop_key('host_template_graph', 'index', 'host_template_id');
	db_install_drop_key('host_template_snmp_query', 'index', 'host_template_id');
	db_install_drop_key('processes', 'index', 'pid');
	db_install_drop_key('snmpagent_cache_notifications', 'index', 'name');
	db_install_drop_key('snmpagent_cache_textual_conventions', 'index', 'name');
	db_install_drop_key('snmpagent_managers_notifications', 'index', 'manager_id_notification');
	db_install_drop_key('snmp_query_graph_rrd', 'index', 'snmp_query_graph_id');
}

