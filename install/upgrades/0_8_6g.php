<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_0_8_6g() {
	/* changes for even longer OIDs */
	db_install_execute("ALTER TABLE `host_snmp_cache` CHANGE `snmp_index` `snmp_index` VARCHAR( 255 ) NOT NULL;");
	db_install_execute("ALTER TABLE `data_local` CHANGE `snmp_index` `snmp_index` VARCHAR( 255 ) NOT NULL;");
	db_install_execute("ALTER TABLE `graph_local` CHANGE `snmp_index` `snmp_index` VARCHAR( 255 ) NOT NULL;");
	db_install_execute("ALTER TABLE `graph_templates_graph` CHANGE `lower_limit` `lower_limit` VARCHAR ( 20 ) DEFAULT '0';");
	db_install_execute("ALTER TABLE `graph_templates_graph` CHANGE `upper_limit` `upper_limit` VARCHAR ( 20 ) DEFAULT '0';");
 }
