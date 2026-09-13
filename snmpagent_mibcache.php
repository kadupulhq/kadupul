<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/* let's report all errors */
error_reporting(E_ALL);

require(__DIR__ . '/include/cli_check.php');

/* allow the script to hang around. */
set_time_limit(0);

chdir(dirname(__FILE__));

$path_mibcache = $config['base_path'] . '/cache/mibcache/mibcache.tmp';
$path_mibcache_lock = $config['base_path'] . '/cache/mibcache/mibcache.lock';

/* remove temporary cache */
@unlink($path_mibcache);

/* remove lock file */
@unlink($path_mibcache_lock);

/* start background caching process if not running */
$php = cacti_escapeshellcmd(read_config_option('path_php_binary'));
$extra_args     = ' ' . cacti_escapeshellarg('./snmpagent_mibcachechild.php');

while(1) {
	if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
		popen('start "CactiSNMPCacheChild" /I ' . $php . ' ' . $extra_args, 'r');
	} else {
		exec($php . ' ' . $extra_args . ' > /dev/null &');
	}

	sleep(30 - time() % 30);
}

