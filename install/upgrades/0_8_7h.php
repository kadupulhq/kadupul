<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

function upgrade_to_0_8_7h() {
	global $config;

	require_once($config['base_path'] . '/lib/poller.php');

	/* speed up the reindexing */
	db_install_add_column('host_snmp_cache', array('name' => 'present', 'type' => 'tinyint', 'NULL' => false, 'default' => '1', 'after' => 'oid'));
	db_install_add_key('host_snmp_cache', 'index', 'present', array('present'));

	db_install_add_column('poller_item', array('name' => 'present', 'type' => 'tinyint', 'NULL' => false, 'default' => '1', 'after' => 'action'));
	db_install_add_key('poller_item', 'index', 'present', array('present'));

	db_install_add_column('poller_reindex', array('name' => 'present', 'type' => 'tinyint', 'NULL' => false, 'default' => '1', 'after' => 'action'));
	db_install_add_key('poller_reindex', 'index', 'present', array('present'));

	db_install_add_column('host', array('name' => 'device_threads', 'type' => 'tinyint(2) unsigned', 'NULL' => false, 'default' => '1', 'after' => 'max_oids'));

	db_install_add_key('data_template_rrd', 'unique index',  'duplicate_dsname_contraint', array('local_data_id', 'data_source_name', 'data_template_id'));
}
