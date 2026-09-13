<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_1_35() {
	db_install_execute("DELETE FROM data_input_data
		WHERE data_input_field_id=0");

	db_install_execute("DELETE FROM snmp_query_graph_rrd
		WHERE data_template_id=0 OR data_template_rrd_id=0");

	db_install_execute("DELETE FROM snmp_query_graph_rrd_sv
		WHERE data_template_id=0");
}

