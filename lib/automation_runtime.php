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

/** Queries on an ownership connection must never reconnect or hide failure. */
function automation_query(PDO $connection, string $sql, array $parameters = array()): PDOStatement {
	for ($attempt = 1; ; $attempt++) {
		$failure = null;
		try {
			$query = $connection->prepare($sql);
			if ($query !== false && $query->execute($parameters)) {
				return $query;
			}
			$error = $query === false ? $connection->errorInfo() : $query->errorInfo();
		} catch (PDOException $exception) {
			$failure = $exception;
			$error = $exception->errorInfo ?? array();
		}
		if (!automation_retry_deadlock($connection, $error, $attempt)) {
			throw $failure ?? new RuntimeException('Automation database operation failed.');
		}
		usleep(random_int(10000, 50000) * $attempt);
	}
}

/**
 * Concurrent workers' claim and completion updates on automation_ips can deadlock
 * in InnoDB. The server rolls back only the victim statement when it runs
 * autocommitted, so re-executing it is safe; without a retry the worker exits and
 * the whole discovery run fails. Inside a transaction the server has rolled back
 * the caller's earlier work too, so that failure is returned to the caller.
 */
function automation_retry_deadlock(PDO $connection, array $error, int $attempt): bool {
	if ((int)($error[1] ?? 0) !== 1213 || $attempt >= 3 || $connection->inTransaction()) {
		return false;
	}
	try {
		$autocommit = $connection->query('SELECT @@autocommit');
		return $autocommit !== false && (int)$autocommit->fetchColumn() === 1;
	} catch (PDOException $ignored) {
		return false;
	}
}

function automation_lock_name(PDO $connection, int $network, int $thread = 0): string {
	$database = automation_query($connection, 'SELECT DATABASE()')->fetchColumn();
	if (!is_string($database) || $database === '') {
		throw new RuntimeException('Automation database identity is unavailable.');
	}
	return 'automation.' . substr(hash('sha256', $database . ':' . $network . ':' . $thread), 0, 48);
}

function automation_lock(PDO $connection, string $name): bool {
	if (automation_query($connection, 'SELECT IS_USED_LOCK(?)', array($name))->fetchColumn() !== null) {
		return false;
	}
	return (int) automation_query($connection, 'SELECT GET_LOCK(?, 0)', array($name))->fetchColumn() === 1;
}

function automation_unlock(PDO $connection, string $name): void {
	automation_query($connection, 'SELECT RELEASE_LOCK(?)', array($name));
}

/** A successor cannot replace a queue while any predecessor child still owns it. */
function automation_acquire_master(PDO $connection, int $network): ?string {
	$name = automation_lock_name($connection, $network);
	if (!automation_lock($connection, $name)) {
		return null;
	}
	$children = array();
	$ready = false;
	try {
		for ($thread = 1; $thread <= 50; $thread++) {
			$child = automation_lock_name($connection, $network, $thread);
			if (!automation_lock($connection, $child)) {
				return null;
			}
			$children[] = $child;
		}
		$ready = true;
		return $name;
	} finally {
		foreach ($children as $child) {
			automation_unlock($connection, $child);
		}
		if (!$ready) {
			automation_unlock($connection, $name);
		}
	}
}

function automation_run_active(PDO $connection, int $network, string $run): bool {
	$name = automation_lock_name($connection, $network);
	return (int) automation_query($connection, "SELECT COUNT(*) FROM automation_processes
		WHERE network_id = ? AND run_id = ? AND task = 'tmaster'
		AND command = 'start' AND connection_id = IS_USED_LOCK(?)", array($network, $run, $name))->fetchColumn() === 1;
}

function automation_claim_address(PDO $connection, int $network, string $run, int $pid, int $thread): array {
	if (!automation_run_active($connection, $network, $run)) {
		throw new RuntimeException('Discovery ownership ended or cancellation was requested.');
	}
	$query = automation_query($connection, 'UPDATE automation_ips SET pid = ?, thread = ?
		WHERE network_id = ? AND run_id = ? AND status = 0 AND pid = 0 LIMIT 1', array($pid, $thread, $network, $run));
	if ($query->rowCount() === 0) {
		return array();
	}
	$row = automation_query($connection, 'SELECT * FROM automation_ips
		WHERE network_id = ? AND run_id = ? AND pid = ? AND thread = ? AND status = 0', array($network, $run, $pid, $thread))->fetch(PDO::FETCH_ASSOC);
	if ($row === false) {
		throw new RuntimeException('Claimed discovery address is unavailable.');
	}
	return $row;
}

function automation_release_worker(PDO $connection, int $network, string $run, int $pid): void {
	automation_query($connection, 'UPDATE automation_ips SET pid = 0, thread = 0, status = 0
		WHERE network_id = ? AND run_id = ? AND pid = ? AND status != 2', array($network, $run, $pid));
	automation_query($connection, 'DELETE FROM automation_processes WHERE network_id = ? AND run_id = ? AND pid = ?', array($network, $run, $pid));
}

/** Decode once so cardinality and enumeration use the same address set. */
function automation_address_range(string $input): ?array {
	$input = trim($input);
	if (strpos($input, '*') !== false) {
		$parts = explode('.', $input);
		if (count($parts) !== 4) {
			return null;
		}
		$count = 1;
		foreach ($parts as &$part) {
			if ($part === '*') {
				$part = null;
				$count *= 256;
			} elseif (!preg_match('/^(0|[1-9][0-9]{0,2})$/D', $part) || (int) $part > 255) {
				return null;
			} else {
				$part = (int) $part;
			}
		}
		unset($part);
		// Trailing wildcards retain subnet network/broadcast exclusion.
		$first = array_search(null, $parts, true);
		$trailing = count(array_filter(array_slice($parts, $first), static fn($part) => $part !== null)) === 0;
		if ($trailing) {
			$start = ip2long(implode('.', array_map(static fn($part) => $part ?? 0, $parts)));
			return array('start' => $start + 1, 'count' => $count - 2, 'cidr' => $first * 8, 'network' => $start, 'broadcast' => $start + $count - 1);
		}
		return array('octets' => $parts, 'count' => $count);
	}
	$parts = explode('/', $input);
	if (count($parts) > 2 || !filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
		return null;
	}
	$ip = ip2long($parts[0]);
	if (count($parts) === 1) {
		return array('start' => $ip, 'count' => 1, 'cidr' => 32, 'network' => $ip, 'broadcast' => $ip);
	}
	$prefix = $parts[1];
	if (preg_match('/^(0|[1-9][0-9]?)$/D', $prefix)) {
		$bits = (int) $prefix;
		if ($bits > 32) {
			return null;
		}
	} else {
		if (!filter_var($prefix, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			return null;
		}
		$binary = sprintf('%032b', ip2long($prefix));
		if (!preg_match('/^1*0*$/D', $binary)) {
			return null;
		}
		$bits = substr_count($binary, '1');
	}
	$size = 2 ** (32 - $bits);
	$start = intdiv($ip, $size) * $size;
	// /31 point-to-point links have two endpoints; /32 has one host.
	return array('start' => $start + ($bits < 31 ? 1 : 0), 'count' => $size - ($bits < 31 ? 2 : 0),
		'cidr' => $bits, 'network' => $start, 'broadcast' => $start + $size - 1);
}

function automation_address_at(array $range, int $offset): string {
	if ($offset < 0 || $offset >= $range['count']) {
		throw new OutOfRangeException('Discovery address offset is outside the range.');
	}
	if (isset($range['start'])) {
		return long2ip($range['start'] + $offset);
	}
	$parts = $range['octets'];
	for ($i = 3; $i >= 0; $i--) {
		if ($parts[$i] === null) {
			$parts[$i] = $offset % 256;
			$offset = intdiv($offset, 256);
		}
	}
	return implode('.', $parts);
}

/** Returns the unconsumed due instant; merely checking must not change it. */
function automation_due_time(array $network): ?int {
	if ((int) $network['sched_type'] === 1) {
		return null;
	}
	$start = strtotime($network['start_at']);
	if ($start === false) {
		throw new RuntimeException('Invalid discovery start time.');
	}
	$next = strtotime($network['next_start']);
	if (str_starts_with($network['next_start'], '0000-00-00') || $next === false) {
		if (in_array((int) $network['sched_type'], array(4, 5), true)) {
			$next = automation_next_schedule($network, $start - 1);
		} else {
			$next = $start;
		}
	}
	return max($start, $next);
}

function automation_next_schedule(array $network, int $after): int {
	$start = strtotime($network['start_at']);
	if ($start === false) {
		throw new RuntimeException('Invalid discovery start time.');
	}
	$after = max($after, $start - 1);
	$type = (int) $network['sched_type'];
	if ($type === 4 || $type === 5) {
		// A February 29-only schedule may need to cross three non-leap years.
		for ($year = (int) date('Y', $after); $year <= (int) date('Y', $after) + 8; $year++) {
			$next = calculateNextStartForYear($network, $year, $after);
			if ($next !== false && $next >= $start) {
				return $next;
			}
		}
		throw new RuntimeException('Discovery schedule has no valid occurrence.');
	}
	$interval = filter_var($network['recur_every'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => 365)));
	if ($interval === false || !in_array($type, array(2, 3), true)) {
		throw new RuntimeException('Invalid discovery recurrence.');
	}
	$zone = new DateTimeZone(date_default_timezone_get());
	$anchor = (new DateTimeImmutable('@' . $start))->setTimezone($zone);
	$day = (new DateTimeImmutable('@' . $after))->setTimezone($zone)->setTime(0, 0);
	$origin = $anchor->setTime(0, 0);
	$elapsed = max(0, (int) $origin->diff($day)->format('%r%a'));
	if ($type === 2) {
		$steps = intdiv($elapsed, $interval);
		$next = $anchor->modify('+' . ($steps * $interval) . ' days');
		if ($next->getTimestamp() <= $after) {
			$next = $next->modify('+' . $interval . ' days');
		}
		return $next->getTimestamp();
	}
	$days = explode(',', $network['day_of_week']);
	if (!$days || array_diff($days, array('1','2','3','4','5','6','7'))) {
		throw new RuntimeException('Invalid discovery weekdays.');
	}
	$weekOrigin = $origin->modify('-' . $origin->format('w') . ' days');
	for ($offset = 0; $offset <= 7 * ($interval + 1); $offset++) {
		$candidate = $day->modify('+' . $offset . ' days')->setTime((int)$anchor->format('H'), (int)$anchor->format('i'), (int)$anchor->format('s'));
		$weeks = intdiv((int) $weekOrigin->diff($candidate->setTime(0, 0))->format('%r%a'), 7);
		if ($candidate->getTimestamp() > $after && $candidate->getTimestamp() >= $start && $weeks % $interval === 0 && in_array((string)((int)$candidate->format('w') + 1), $days, true)) {
			return $candidate->getTimestamp();
		}
	}
	throw new RuntimeException('Discovery schedule has no next occurrence.');
}

function automation_default_connection(): PDO {
	global $database_sessions, $database_hostname, $database_port, $database_default;
	$connection = $database_sessions["$database_hostname:$database_port:$database_default"] ?? null;
	if (!$connection instanceof PDO) {
		throw new RuntimeException('Discovery requires its primary database connection.');
	}
	return $connection;
}

function automation_require_schema(PDO $connection): void {
	$engines = automation_query($connection, "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
		WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('automation_ips', 'automation_processes', 'automation_networks')")->fetchAll(PDO::FETCH_KEY_PAIR);
	if (count($engines) !== 3 || count(array_filter($engines, static fn($engine) => strtoupper($engine) === 'INNODB')) !== 3) {
		throw new RuntimeException('Upgrade the discovery schema before starting workers.');
	}
	$keys = automation_query($connection, "SHOW INDEX FROM automation_ips WHERE Key_name = 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);
	usort($keys, static fn($a, $b) => $a['Seq_in_index'] <=> $b['Seq_in_index']);
	if (array_column($keys, 'Column_name') !== array('network_id', 'ip_address')) {
		throw new RuntimeException('Upgrade the discovery queue identity before starting workers.');
	}
	$claims = automation_query($connection, "SHOW INDEX FROM automation_ips WHERE Key_name = 'claim'")->fetchAll(PDO::FETCH_ASSOC);
	usort($claims, static fn($a, $b) => $a['Seq_in_index'] <=> $b['Seq_in_index']);
	if (array_column($claims, 'Column_name') !== array('network_id', 'run_id', 'status', 'pid')) {
		throw new RuntimeException('Upgrade the discovery claim index before starting workers.');
	}
	foreach (array('automation_ips', 'automation_processes') as $table) {
		$column = automation_query($connection, "SHOW COLUMNS FROM $table WHERE Field = 'run_id'")->fetch(PDO::FETCH_ASSOC);
		if (!$column || strtolower($column['Type']) !== 'char(32)' || $column['Null'] !== 'NO' || $column['Default'] !== '') {
			throw new RuntimeException('Upgrade the discovery run identity before starting workers.');
		}
	}
	$column = automation_query($connection, "SHOW COLUMNS FROM automation_processes WHERE Field = 'connection_id'")->fetch(PDO::FETCH_ASSOC);
	if (!$column || !preg_match('/^bigint(?:\\([0-9]+\\))? unsigned$/D', strtolower($column['Type'])) || $column['Null'] !== 'NO' || (string)$column['Default'] !== '0') {
		throw new RuntimeException('Upgrade the discovery connection identity before starting workers.');
	}
}

/** Retain process handles so cancellation can never signal a reused OS PID. */
function automation_start_child(array $arguments) {
	$php = realpath(read_config_option('path_php_binary'));
	if ($php === false || !is_executable($php)) {
		throw new RuntimeException('Discovery PHP executable is unavailable.');
	}
	$null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
	$process = proc_open(array_merge(array($php), $arguments), array(
		0 => array('file', $null, 'r'), 1 => array('file', $null, 'a'), 2 => array('file', $null, 'a')
	), $pipes, null, null, array('bypass_shell' => true));
	if (!is_resource($process)) {
		throw new RuntimeException('Discovery worker could not be started.');
	}
	return $process;
}

function automation_stop_children(array $children): void {
	foreach ($children as $process) {
		if (is_resource($process) && proc_get_status($process)['running']) {
			proc_terminate($process);
		}
	}
	$until = microtime(true) + 5;
	do {
		$running = false;
		foreach ($children as $process) {
			if (is_resource($process) && proc_get_status($process)['running']) {
				$running = true;
			}
		}
		if ($running) {
			usleep(100000);
		}
	} while ($running && microtime(true) < $until);
	foreach ($children as $process) {
		if (is_resource($process)) {
			if (proc_get_status($process)['running']) {
				proc_terminate($process, 9);
			}
			// Never block finalization on an uninterruptible child; its DB lock
			// prevents a successor from replacing that child's queue.
			if (!proc_get_status($process)['running']) {
				proc_close($process);
			}
		}
	}
}

function automation_finish_run(PDO $connection, array $network, string $run, int $expected, int $threads, bool $scheduled, float $started): void {
	if (!$connection->beginTransaction()) {
		throw new RuntimeException('Discovery transaction could not be started.');
	}
	try {
		$queue = automation_query($connection, 'SELECT COUNT(*) AS total, COALESCE(SUM(status != 2), 0) AS pending
			FROM automation_ips WHERE network_id = ? AND run_id = ?', array($network['id'], $run))->fetch(PDO::FETCH_ASSOC);
		$done = (int) automation_query($connection, "SELECT COUNT(*) FROM automation_processes
			WHERE network_id = ? AND run_id = ? AND task = 'collector' AND status = 'done'", array($network['id'], $run))->fetchColumn();
		if ((int)$queue['total'] !== $expected || (int)$queue['pending'] !== 0 || $done !== $threads) {
			throw new RuntimeException('Discovery has unfinished work.');
		}
		$totals = automation_query($connection, 'SELECT COALESCE(SUM(up_hosts), 0) AS up, COALESCE(SUM(snmp_hosts), 0) AS snmp
			FROM automation_processes WHERE network_id = ? AND run_id = ?', array($network['id'], $run))->fetch(PDO::FETCH_ASSOC);
		if ($scheduled) {
			$fields = array('next_start', 'start_at', 'sched_type', 'recur_every', 'day_of_week', 'month', 'day_of_month', 'monthly_week', 'monthly_day');
			$where = array();
			$params = array(date('Y-m-d H:i:s', automation_next_schedule($network, time())), $network['id']);
			foreach ($fields as $field) {
				$where[] = "$field <=> ?";
				$params[] = $network[$field];
			}
			// An administrator's schedule edit must not be overwritten by this run.
			automation_query($connection, 'UPDATE automation_networks SET next_start = ? WHERE id = ? AND ' . implode(' AND ', $where), $params);
		}
		automation_query($connection, "UPDATE automation_networks SET up_hosts = ?, snmp_hosts = ?, last_runtime = ?, last_status = 'completed' WHERE id = ?",
			array($totals['up'], $totals['snmp'], microtime(true) - $started, $network['id']));
		automation_query($connection, 'DELETE FROM automation_ips WHERE network_id = ? AND run_id = ?', array($network['id'], $run));
		automation_query($connection, 'DELETE FROM automation_processes WHERE network_id = ? AND run_id = ?', array($network['id'], $run));
		if (!$connection->commit()) {
			throw new RuntimeException('Discovery completion was not committed.');
		}
	} catch (Throwable $error) {
		try {
			if ($connection->inTransaction()) {
				$connection->rollBack();
			}
		} catch (Throwable $ignored) {
			// Preserve the original failure when the connection is unavailable.
		}
		throw $error;
	}
}

function automation_run_network(PDO $connection, int $networkId, int $pollerId, bool $scheduled, bool $force, bool $debug): bool {
	if ($connection->inTransaction()) {
		throw new RuntimeException('Discovery requires its own transaction.');
	}
	$lock = automation_acquire_master($connection, $networkId);
	if ($lock === null) {
		return false;
	}
	$children = array();
	$run = bin2hex(random_bytes(16));
	$started = microtime(true);
	try {
		automation_require_schema($connection);
		$network = automation_query($connection, 'SELECT * FROM automation_networks WHERE id = ? AND poller_id = ?', array($networkId, $pollerId))->fetch(PDO::FETCH_ASSOC);
		if (!$network || ($network['enabled'] !== 'on' && !$force)) {
			return false;
		}
		if ($scheduled) {
			$due = automation_due_time($network);
			if ($due === null || $due > time()) {
				return false;
			}
		}
		$threads = (int) $network['threads'];
		if ($threads < 1 || $threads > 50 || (int)$network['snmp_id'] < 1) {
			throw new RuntimeException('Invalid discovery worker configuration.');
		}
		$deadline = $started + ((int)$network['run_limit'] > 0 ? (int)$network['run_limit'] : 1200);
		$previous = getNetworkDevices($networkId);
		if ($previous === false) {
			throw new RuntimeException('Previous discovery inventory is unavailable.');
		}
		if (!$connection->beginTransaction()) {
			throw new RuntimeException('Discovery transaction could not be started.');
		}
		automation_query($connection, 'DELETE FROM automation_ips WHERE network_id = ?', array($networkId));
		automation_query($connection, 'DELETE FROM automation_processes WHERE network_id = ?', array($networkId));
		$expected = automation_primeIPAddressTable($networkId, $run, $connection, $deadline);
		automation_query($connection, "INSERT INTO automation_processes (pid, poller_id, network_id, run_id, connection_id, task, status, heartbeat, command)
			VALUES (?, ?, ?, ?, CONNECTION_ID(), 'tmaster', 'running', NOW(), 'start')", array(getmypid(), $pollerId, $networkId, $run));
		automation_query($connection, "UPDATE automation_networks SET last_started = NOW(), last_status = 'running' WHERE id = ?", array($networkId));
		if (!$connection->commit()) {
			throw new RuntimeException('Discovery preparation was not committed.');
		}
		for ($thread = 1; $thread <= $threads; $thread++) {
			$args = array('-q', dirname(__DIR__) . '/poller_automation.php', '--network=' . $networkId, '--poller=' . $pollerId, '--thread=' . $thread, '--run=' . $run);
			if ($debug) {
				$args[] = '--debug';
			}
			$children[$thread] = automation_start_child($args);
		}
		while ($children) {
			if (microtime(true) >= $deadline || !automation_run_active($connection, $networkId, $run)) {
				throw new RuntimeException('Discovery was cancelled or exceeded its run limit.');
			}
			foreach ($children as $thread => $process) {
				$status = proc_get_status($process);
				if (!$status['running']) {
					proc_close($process);
					unset($children[$thread]);
					if ($status['exitcode'] !== 0) {
						throw new RuntimeException('Discovery worker failed.');
					}
				}
			}
			if ($children) {
				usleep(100000);
			}
		}
		automation_finish_run($connection, $network, $run, $expected, $threads, $scheduled, $started);
		try {
			reportNetworkStatus($networkId, $previous);
		} catch (Throwable $error) {
			cacti_log('WARNING: Discovery completed but notification failed for network ' . $networkId . '.', false, 'AUTOM8');
		}
		return true;
	} catch (Throwable $error) {
		try {
			if ($connection->inTransaction()) {
				$connection->rollBack();
			}
		} catch (Throwable $ignored) {
			// Preserve the original failure when the connection is unavailable.
		}
		try {
			automation_query($connection, "UPDATE automation_processes SET command = 'cancel', status = 'failed' WHERE network_id = ? AND run_id = ? AND task = 'tmaster'", array($networkId, $run));
			automation_query($connection, "UPDATE automation_networks SET last_status = 'failed; retry discovery', last_runtime = ? WHERE id = ?", array(microtime(true) - $started, $networkId));
		} catch (Throwable $ignored) {
			// An unavailable database cannot acknowledge failure; leave the due time intact.
		}
		cacti_log('ERROR: Discovery failed for network ' . $networkId . '; unfinished work remains retryable.', false, 'AUTOM8');
		return false;
	} finally {
		automation_stop_children($children);
		try {
			automation_unlock($connection, $lock);
		} catch (Throwable $ignored) {
			// A lost connection releases its server-owned lock.
		}
	}
}
