<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_1_2_14() {
	db_install_add_column('data_local', array('name' => 'orphan', 'type' => 'tinyint', 'unsigned' => true, 'NULL' => false, 'default' => '0', 'after' => 'snmp_index'));
}
