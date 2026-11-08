<?php
declare(strict_types=1);
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

function upgrade_to_1_2_33() {
	// Stop discovery workers before upgrading all collectors. Retain old rows
	// with an empty run identity; the next owned run replaces them atomically.
	db_install_add_column('automation_ips', array('name' => 'run_id', 'type' => 'char(32)', 'NULL' => false, 'default' => ''));
	db_install_add_column('automation_processes', array('name' => 'run_id', 'type' => 'char(32)', 'NULL' => false, 'default' => ''));
	db_install_add_column('automation_processes', array('name' => 'connection_id', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'default' => '0'));
	db_install_execute('UPDATE automation_ips SET network_id = 0 WHERE network_id IS NULL');
	db_install_execute('ALTER TABLE automation_ips MODIFY network_id int(10) unsigned NOT NULL DEFAULT 0,
		DROP PRIMARY KEY, ADD PRIMARY KEY (network_id, ip_address), ENGINE=InnoDB ROW_FORMAT=Dynamic');
	db_install_add_key('automation_ips', 'INDEX', 'claim', array('network_id', 'run_id', 'status', 'pid'));
	db_install_execute('ALTER TABLE automation_processes ENGINE=InnoDB ROW_FORMAT=Dynamic');
	db_install_execute('ALTER TABLE automation_networks ENGINE=InnoDB ROW_FORMAT=Dynamic');
	require_once dirname(__DIR__, 2) . '/lib/automation_runtime.php';
	try {
		automation_require_schema(automation_default_connection());
	} catch (Throwable $error) {
		db_install_add_cache(DB_STATUS_ERROR, 'Discovery schema validation failed; retry the upgrade after correcting the database error.');
	}
}
