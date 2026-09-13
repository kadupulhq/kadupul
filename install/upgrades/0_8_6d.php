<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_0_8_6d() {
	/* changes for long OIDs */
	db_install_execute("ALTER TABLE `host_snmp_cache` CHANGE `snmp_index` `snmp_index` VARCHAR( 100 ) NOT NULL;");
	db_install_execute("ALTER TABLE `data_local` CHANGE `snmp_index` `snmp_index` VARCHAR( 100 ) NOT NULL;");
	db_install_execute("ALTER TABLE `graph_local` CHANGE `snmp_index` `snmp_index` VARCHAR( 100 ) NOT NULL;");
}
