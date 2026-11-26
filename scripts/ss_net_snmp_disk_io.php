#!/usr/bin/env php
<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

global $config;

if (!isset($called_by_script_server)) {
	include_once(dirname(__FILE__) . '/../include/cli_check.php');
	include_once(dirname(__FILE__) . '/../lib/snmp.php');

	array_shift($_SERVER['argv']);

	print call_user_func_array('ss_net_snmp_disk_io', $_SERVER['argv']);
} else {
	include_once(dirname(__FILE__) . '/../lib/snmp.php');
}

function ss_net_snmp_disk_io($host_id_or_hostname = '') {
	global $environ, $poller_id, $config;

	if (empty($host_id_or_hostname) || $host_id_or_hostname === NULL) {
		return 'reads:0 writes:0';
	} elseif (!ctype_digit((string) $host_id_or_hostname)) {
		$host_id = db_fetch_cell_prepared('SELECT id
			FROM host
			WHERE hostname = ?',
			array($host_id_or_hostname));
	} else {
		$host_id = $host_id_or_hostname;
	}

	if (!ctype_digit((string) $host_id) || (int) $host_id <= 0) {
		return 'reads:0 writes:0';
	}

	$host_id        = (int) $host_id;
	$safe_poller_id = (is_scalar($poller_id) && ctype_digit((string) $poller_id) && (int) $poller_id > 0) ? (int) $poller_id : 1;
	$tmpdir         = ss_net_snmp_disk_io_state_dir($config, $environ);

	if ($environ != 'realtime') {
		$tmpfile = $host_id . '_io';
	} else {
		$tmpfile = $host_id . '_' . $safe_poller_id . '_io_rt';
	}

	$previous = array();
	$found    = false;

	if ($tmpdir !== false) {
		$loaded = ss_net_snmp_disk_io_read_state($tmpdir, $tmpfile);
		if ($loaded !== null) {
			$previous = $loaded;
			$found    = true;
		}
	}

	$indexes = array();
	$host = db_fetch_row_prepared('SELECT *
		FROM host
		WHERE id = ?',
		array($host_id));

	if (!cacti_sizeof($host)) {
		return 'reads:0 writes:0';
	}

	$uptime  = cacti_snmp_get($host['hostname'],
		$host['snmp_community'],
		'.1.3.6.1.2.1.1.3.0',
		$host['snmp_version'],
		$host['snmp_username'],
		$host['snmp_password'],
		$host['snmp_auth_protocol'],
		$host['snmp_priv_passphrase'],
		$host['snmp_priv_protocol'],
		$host['snmp_context'],
		$host['snmp_port'],
		$host['snmp_timeout'],
		$host['ping_retries'],
		SNMP_POLLER,
		$host['snmp_engine_id']);

	$current['uptime'] = $uptime;

	$names  = cacti_snmp_walk($host['hostname'],
		$host['snmp_community'],
		'.1.3.6.1.4.1.2021.13.15.1.1.2',
		$host['snmp_version'],
		$host['snmp_username'],
		$host['snmp_password'],
		$host['snmp_auth_protocol'],
		$host['snmp_priv_passphrase'],
		$host['snmp_priv_protocol'],
		$host['snmp_context'],
		$host['snmp_port'],
		$host['snmp_timeout'],
		$host['ping_retries'],
		SNMP_POLLER,
		$host['snmp_engine_id']);

	foreach($names as $measure) {
		if (substr($measure['value'],0,2) == 'sd' || substr($measure['value'],0,4) == 'nvme' || substr($measure['value'],0,2) == 'vm') {
			if (is_numeric(substr(strrev($measure['value']),0,1))) {
				continue;
			}

			$parts = explode('.', $measure['oid']);
			$indexes[$parts[cacti_sizeof($parts)-1]] = $parts[cacti_sizeof($parts)-1];
		}
	}

	$reads = $writes = 0;

	if (cacti_sizeof($indexes)) {
		$iops = cacti_snmp_walk($host['hostname'],
			$host['snmp_community'],
			'.1.3.6.1.4.1.2021.13.15.1.1.5',
			$host['snmp_version'],
			$host['snmp_username'],
			$host['snmp_password'],
			$host['snmp_auth_protocol'],
			$host['snmp_priv_passphrase'],
			$host['snmp_priv_protocol'],
			$host['snmp_context'],
			$host['snmp_port'],
			$host['snmp_timeout'],
			$host['ping_retries'],
			$host['max_oids'],
			SNMP_POLLER,
			$host['snmp_engine_id']);

		foreach($iops as $measure) {
			$parts = explode('.', $measure['oid']);
			$index = $parts[cacti_sizeof($parts)-1];

			if (array_key_exists($index, $indexes)) {
				if (!isset($previous['uptime'])) {
					$reads = 'U';
				} elseif ($current['uptime'] < $previous['uptime']) {
					$reads = 'U';
				} elseif (!isset($previous["dr$index"])) {
					$reads = 'U';
				} elseif ($previous["dr$index"] > $measure['value']) {
					if ($reads != 'U') {
						$reads += intval($measure['value']) + 4294967295 - intval($previous["dr$index"]) - intval($previous["dr$index"]);
					} else {
						$reads = intval($measure['value']) + 4294967295 - intval($previous["dr$index"]) - intval($previous["dr$index"]);
					}
				} else {
					if ($reads != 'U') {
						$reads += $measure['value'] - $previous["dr$index"];
					} else {
						$reads = $measure['value'] - $previous["dr$index"];
					}
				}

				$current["dr$index"] = $measure['value'];
			}
		}

		$iops = cacti_snmp_walk($host['hostname'],
			$host['snmp_community'],
			'.1.3.6.1.4.1.2021.13.15.1.1.6',
			$host['snmp_version'],
			$host['snmp_username'],
			$host['snmp_password'],
			$host['snmp_auth_protocol'],
			$host['snmp_priv_passphrase'],
			$host['snmp_priv_protocol'],
			$host['snmp_context'],
			$host['snmp_port'],
			$host['snmp_timeout'],
			$host['ping_retries'],
			$host['max_oids'],
			SNMP_POLLER,
			$host['snmp_engine_id']);

		foreach($iops as $measure) {
			$parts = explode('.', $measure['oid']);
			$index = $parts[cacti_sizeof($parts)-1];

			if (array_key_exists($index, $indexes)) {
				if (!isset($previous['uptime'])) {
					$writes = 'U';
				} elseif ($current['uptime'] < $previous['uptime']) {
					$writes = 'U';
				} elseif (!isset($previous["dw$index"])) {
					$writes = 'U';
				} elseif ($previous["dw$index"] > $measure['value']) {
					if ($writes != 'U') {
						$writes += intval($measure['value']) + 4294967295 - intval($previous["dw$index"]) - intval($previous["dw$index"]);
					} else {
						$writes = intval($measure['value']) + 4294967295 - intval($previous["dw$index"]) - intval($previous["dw$index"]);
					}
				} else {
					if ($writes != 'U') {
						$writes += $measure['value'] - $previous["dw$index"];
					} else {
						$writes = $measure['value'] - $previous["dw$index"];
					}
				}

				$current["dw$index"] = $measure['value'];
			}
		}

		$data = json_encode($current);
		if ($tmpdir !== false && is_string($data)) {
			ss_net_snmp_disk_io_write_state($tmpdir, $tmpfile, $data);
		}
	}

	if ($found) {
		return "reads:$reads writes:$writes";
	} else {
		return 'reads:0 writes:0';
	}
}

function ss_net_snmp_disk_io_state_dir($config, $environ) {
	if (!class_exists(\Symfony\Component\Filesystem\Path::class)) {
		require_once __DIR__ . '/../src/Platform/Infrastructure/Legacy/LegacyComponentAutoloader.php';
		\Kadupul\Platform\Infrastructure\Legacy\LegacyComponentAutoloader::register(dirname(__DIR__));
	}

	if (is_array($config) && isset($config['base_path']) && is_string($config['base_path']) && $config['base_path'] !== '') {
		$anchor   = \Symfony\Component\Filesystem\Path::join($config['base_path'], 'cache');
		$base_dir = \Symfony\Component\Filesystem\Path::join($anchor, 'net_snmp_disk');
	} elseif (is_array($config) && isset($config['rra_path']) && is_string($config['rra_path']) && $config['rra_path'] !== '') {
		$anchor   = $config['rra_path'];
		$base_dir = \Symfony\Component\Filesystem\Path::join($anchor, '.net_snmp_disk');
	} else {
		return false;
	}

	$anchor_real = realpath($anchor);
	if ($anchor_real === false || !is_dir($anchor_real) || is_link($anchor)) {
		return false;
	}

	$sub_dir   = ($environ != 'realtime') ? 'poller' : 'realtime';
	$state_dir = \Symfony\Component\Filesystem\Path::join($base_dir, $sub_dir);
	$euid      = function_exists('posix_geteuid') ? posix_geteuid() : null;

	foreach (array($base_dir, $state_dir) as $dir) {
		clearstatcache(true, $dir);
		if (is_link($dir)) {
			return false;
		}

		if (!file_exists($dir)) {
			if (!@mkdir($dir, 0700, false) && !is_dir($dir)) {
				return false;
			}
			@chmod($dir, 0700);
		}

		clearstatcache(true, $dir);
		$stat = @lstat($dir);
		if ($stat === false || ($stat['mode'] & 0170000) !== 0040000) {
			return false;
		}

		if ($euid !== null && $stat['uid'] !== $euid) {
			return false;
		}

		if (($stat['mode'] & 0022) !== 0) {
			@chmod($dir, 0700);
			clearstatcache(true, $dir);
			$stat = @lstat($dir);
			if ($stat === false || ($stat['mode'] & 0022) !== 0) {
				return false;
			}
		}

		$resolved = realpath($dir);
		if ($resolved === false || !str_starts_with($resolved, rtrim($anchor_real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
			return false;
		}
	}

	return $state_dir;
}

function ss_net_snmp_disk_io_read_state($state_dir, $filename) {
	if (!is_string($state_dir) || $state_dir === '' || preg_match('/^[0-9]+(?:_[0-9]+)?_io(?:_rt)?\z/', $filename) !== 1) {
		return null;
	}

	$path = \Symfony\Component\Filesystem\Path::join($state_dir, $filename);
	clearstatcache(true, $path);
	if (is_link($path)) {
		return null;
	}

	$stat = @lstat($path);
	if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
		return null;
	}

	if (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()) {
		return null;
	}

	if (($stat['mode'] & 0022) !== 0) {
		return null;
	}

	$raw = @file_get_contents($path);
	if (!is_string($raw)) {
		return null;
	}

	$decoded = json_decode($raw, true);

	return is_array($decoded) ? $decoded : null;
}

function ss_net_snmp_disk_io_write_state($state_dir, $filename, $data) {
	if (!is_string($state_dir) || $state_dir === '' || preg_match('/^[0-9]+(?:_[0-9]+)?_io(?:_rt)?\z/', $filename) !== 1) {
		return false;
	}

	$path = \Symfony\Component\Filesystem\Path::join($state_dir, $filename);
	clearstatcache(true, $path);
	if (is_link($path)) {
		return false;
	}

	$tmp = \Symfony\Component\Filesystem\Path::join($state_dir, '.' . $filename . '.' . bin2hex(random_bytes(8)) . '.tmp');
	$fh  = @fopen($tmp, 'xb');
	if ($fh === false) {
		return false;
	}

	$written = @fwrite($fh, $data);
	@fflush($fh);
	@fclose($fh);

	if ($written !== strlen($data)) {
		@unlink($tmp);

		return false;
	}

	@chmod($tmp, 0600);
	clearstatcache(true, $path);
	if (is_link($path)) {
		@unlink($tmp);

		return false;
	}

	if (!@rename($tmp, $path)) {
		@unlink($tmp);

		return false;
	}

	return true;
}

