<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require(__DIR__ . '/include/cli_check.php');

/* let's report all errors */
error_reporting(E_ALL);

/* allow the script to hang around. */
set_time_limit(0);

chdir(dirname(__FILE__));

$last_time = time()-30;
$cache     = array();

$path_mibcache      = $config['base_path'] . '/cache/mibcache/mibcache.tmp';
$path_mibcache_lock = $config['base_path'] . '/cache/mibcache/mibcache.lock';

/* check mib cache table status */
$mibcache_changed = db_fetch_cell_prepared("SHOW TABLE STATUS
	WHERE `Name` LIKE 'snmpagent_cache'
	AND (UNIX_TIMESTAMP(`Update_time`)) >= ?",
	array($last_time));

if ($mibcache_changed !== null || file_exists($path_mibcache) === false) {
	$objects = db_fetch_assoc("SELECT `oid`, LOWER(type) as type, `otype`, `max-access`, `value`
		FROM snmpagent_cache");

	if (cacti_sizeof($objects)) {
		$oids = array();

		foreach($objects as &$object) {
			$oids[] = $object['oid'];

			$object = ($object['otype'] == 'DATA' && $object['max-access'] != 'not-accessible') ? array('type' => $object['type'], 'value' => $object['value']) : false;
		}

		/* natural sorting with MySQL is not available - especially not for OIDs */
		natsort($oids);

		$last_accessible_object = false;
		$next_accessible_object_required = array();

		foreach($oids as $key => $oid) {
			if ($objects[$key]) {
				if ($last_accessible_object) {
					$cache[$last_accessible_object]['next'] = $oid;
				}

				if (cacti_sizeof($next_accessible_object_required)>0) {
					foreach($next_accessible_object_required as $next_accessible_object_required_oid) {
						$cache[$next_accessible_object_required_oid]['next'] = $oid;
					}

					$next_accessible_object_required = array();
				}

				$last_accessible_object = $oid;
			} else {
				$next_accessible_object_required[] = $oid;
			}

			$cache[$oid] = $objects[$key];
		}
	}

	/* create lock file */
	$lock = fopen($path_mibcache_lock, 'w');

	/* Note: If SNMPAgent plugin has been disabled the cache will be truncated automatically */
	if (cacti_sizeof($cache)) {
		file_put_contents($path_mibcache, '<?php $cache = ' . var_export($cache, true) . ';', LOCK_EX);
	}

	/* destroy lock file */
	fclose($lock);
	unlink($path_mibcache_lock);
}

return;
