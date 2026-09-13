#!/usr/bin/env php
<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

error_reporting(0);

if (!isset($called_by_script_server)) {
	include_once(dirname(__FILE__) . '/../include/cli_check.php');

	array_shift($_SERVER['argv']);

	print call_user_func_array('ss_webseer', $_SERVER['argv']);
}

function ss_webseer($cmd = 'index', $arg1 = '', $arg2 = '') {
	if ($cmd == 'index') {
		if (db_table_exists('plugin_webseer_urls')) {
			$exports = db_fetch_assoc('SELECT id FROM plugin_webseer_urls ORDER BY id');

			if (cacti_sizeof($exports)) {
				foreach ($exports as $export) {
					print $export['id'] . PHP_EOL;
				}
			}
		}
	} elseif ($cmd == 'query') {
		$arg = $arg1;

		if (db_table_exists('plugin_webseer_urls')) {
			if ($arg == 'webseerId') {
				$arr = db_fetch_assoc('SELECT id FROM plugin_webseer_urls ORDER BY id');

				if (cacti_sizeof($arr)) {
					foreach ($arr as $item) {
						print $item['id'] . '!' . $item['id'] . PHP_EOL;
					}
				}
			} elseif ($arg == 'webseerName') {
				$arr = db_fetch_assoc('SELECT id, display_name AS name FROM plugin_webseer_urls ORDER BY id');

				if (cacti_sizeof($arr)) {
					foreach ($arr as $item) {
						print $item['id'] . '!' . $item['name'] . PHP_EOL;
					}
				}
			}
		}
	} elseif ($cmd == 'get') {
		$arg   = $arg1;
		$index = $arg2;
		$value = 'U';

		if (db_table_exists('plugin_webseer_urls')) {
			switch($arg) {
				case 'lookupTime':
					$value = db_fetch_cell_prepared('SELECT namelookup_time
						FROM plugin_webseer_urls
						WHERE id = ?',
						array($index));

					break;
				case 'connectTime':
					$value = db_fetch_cell_prepared('SELECT connect_time
						FROM plugin_webseer_urls
						WHERE id = ?',
						array($index));

					break;
				case 'redirectTime':
					$value = db_fetch_cell_prepared('SELECT redirect_time
						FROM plugin_webseer_urls
						WHERE id = ?',
						array($index));

					break;
				case 'totalTime':
					$value = db_fetch_cell_prepared('SELECT total_time
						FROM plugin_webseer_urls
						WHERE id = ?',
						array($index));

					break;
				case 'downloadSpeed':
					$value = db_fetch_cell_prepared('SELECT speed_download
						FROM plugin_webseer_urls
						WHERE id = ?',
						array($index));

					break;
				case 'downloadSize':
					$value = db_fetch_cell_prepared('SELECT size_download
						FROM plugin_webseer_urls
						WHERE id = ?',
						array($index));

					break;
				case 'checkStatus':
					$value = db_fetch_cell_prepared('SELECT result
						FROM plugin_webseer_urls
						WHERE id = ?',
						array($index));

					break;
			}
		}

		return ($value === '' || $value === false || $value === null ? 'U' : $value);
	}

	return 'U';
}
