<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_2_27() {
	db_install_execute("ALTER TABLE `poller_item` MODIFY `snmp_priv_protocol` char(7) NOT NULL DEFAULT ''");
	db_install_execute("ALTER TABLE `host` MODIFY `snmp_priv_protocol` char(7) DEFAULT ''");
	db_install_execute("ALTER TABLE `automation_devices` MODIFY `snmp_priv_protocol` char(7) DEFAULT ''");
	db_install_execute("ALTER TABLE `automation_snmp_items` MODIFY `snmp_priv_protocol` char(7) DEFAULT ''");
	db_install_execute("ALTER TABLE `snmpagent_managers` MODIFY `snmp_priv_protocol` char(7) NOT NULL DEFAULT ''");
	
	$data_input_field_id = db_fetch_cell_prepared('SELECT id FROM data_input_fields WHERE hash = ?', array('35637c344d84d8aa3a4dc50e4a120b3f'));

	if ($data_input_field_id > 0) {
		db_execute_prepared('DELETE FROM data_input_fields WHERE id = ?', array($data_input_field_id));
		db_execute_prepared('DELETE FROM data_input_data WHERE data_input_field_id = ?', array($data_input_field_id));
	}
}

