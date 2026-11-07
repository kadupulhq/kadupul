<?php
declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

require_once __DIR__ . '/database_atomic.php';

/** Private, durable local handoff while the collector database is unavailable. */
function poller_output_spool(int $poller_id): SQLite3|false {
	global $config;
	$windows = PHP_OS_FAMILY === 'Windows';
	if ($poller_id < 1 || (!$windows && !function_exists('posix_geteuid'))) {
		return false;
	}
	$directory = $config['poller_output_spool'] ?? sys_get_temp_dir() . '/cacti-output-' . substr(hash('sha256', $config['base_path']), 0, 16);
	$parent = realpath(dirname($directory));
	if ($parent === false || is_link($directory)) {
		return false;
	}
	$directory = $parent . '/' . basename($directory);
	if (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory)) {
		return false;
	}
	clearstatcache(true, $directory);
	$stat = lstat($directory);
	if ($stat === false || ($stat['mode'] & 0170000) !== 0040000 || (!$windows && ($stat['uid'] !== posix_geteuid() || ($stat['mode'] & 0077) !== 0))) {
		return false;
	}
	// Windows inherits the operator-managed directory ACL; POSIX uses mode 0700.
	$path = $directory . '/poller-' . $poller_id . '.sqlite';
	if (!file_exists($path) && !is_link($path)) {
		$mask = umask(0077);
		try {
			$file = @fopen($path, 'x');
			if ($file !== false) {
				fclose($file);
			}
		} finally {
			umask($mask);
		}
	}
	clearstatcache(true, $path);
	$stat = lstat($path);
	if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1 || (!$windows && ($stat['uid'] !== posix_geteuid() || ($stat['mode'] & 0077) !== 0))) {
		return false;
	}
	$db = new SQLite3($path, SQLITE3_OPEN_READWRITE);
	$db->enableExceptions(true);
	$db->busyTimeout(5000);
	$db->exec('PRAGMA synchronous=FULL');
	$db->exec('PRAGMA max_page_count=16384');
	$db->exec('CREATE TABLE IF NOT EXISTS batches (id INTEGER PRIMARY KEY AUTOINCREMENT, time INTEGER NOT NULL, redirect INTEGER NOT NULL, payload BLOB NOT NULL)');
	return $db;
}

/** Keep the original batch time and redirect destination through every retry. */
function poller_output_enqueue(SQLite3 $spool, array $rows, bool $redirect): bool {
	if (!$rows || count($rows) > 2002) {
		return false;
	}
	$payload = array();
	foreach ($rows as $row) {
		if (!is_array($row) || array_keys($row) !== array(0, 1, 2) || !is_int($row[0]) || $row[0] <= 0 || !is_string($row[1]) || !is_string($row[2])) {
			return false;
		}
		$payload[] = array($row[0], base64_encode($row[1]), base64_encode($row[2]));
	}
	$json = json_encode($payload, JSON_THROW_ON_ERROR);
	if (strlen($json) > 4 * 1024 * 1024) {
		return false;
	}
	$statement = $spool->prepare('INSERT INTO batches (time,redirect,payload) VALUES (?,?,?)');
	$statement->bindValue(1, time(), SQLITE3_INTEGER);
	$statement->bindValue(2, (int) $redirect, SQLITE3_INTEGER);
	$statement->bindValue(3, $json, SQLITE3_BLOB);
	$result = $statement->execute();
	return $result !== false && $result->finalize() && $statement->close();
}

/** Acknowledge only a complete database handoff; duplicate keys are idempotent. */
function poller_output_deliver(array $batch, $connection = false): bool {
	global $database_sessions, $database_hostname, $database_port, $database_default;
	if ($connection === false) {
		$connection = $database_sessions["$database_hostname:$database_port:$database_default"] ?? null;
	}
	// A savepoint cannot acknowledge a durable queue before its caller commits.
	if (!$connection instanceof PDO || $connection->inTransaction()) {
		return false;
	}
	$rows = json_decode($batch['payload'], true, 512, JSON_THROW_ON_ERROR);
	if (!is_array($rows) || !$rows || count($rows) > 2002) {
		return false;
	}
	$parameters = array();
	foreach ($rows as $row) {
		if (!is_array($row) || array_keys($row) !== array(0, 1, 2) || !is_int($row[0]) || $row[0] <= 0 || !is_string($row[1]) || !is_string($row[2])) {
			return false;
		}
		$name = base64_decode($row[1], true);
		$output = base64_decode($row[2], true);
		if ($name === false || $output === false) {
			return false;
		}
		array_push($parameters, $row[0], $name, (int) $batch['time'], $output);
	}
	$tables = array('poller_output');
	if ($batch['redirect']) {
		$tables[] = 'poller_output_boost';
	}
	$values = implode(',', array_fill(0, count($rows), '(?,?,FROM_UNIXTIME(?),?)'));
	return db_atomic($tables, static function (PDO $db) use ($tables, $values, $parameters): bool {
		foreach ($tables as $table) {
			// Ignore only duplicate keys, never truncation or invalid column values.
			$sql = 'INSERT INTO ' . $table . ' (local_data_id,rrd_name,time,output) VALUES ' . $values . ' ON DUPLICATE KEY UPDATE local_data_id=local_data_id';
			if (!db_atomic_execute($db, $sql, $parameters)) {
				return false;
			}
			$warnings = $db->query('SHOW WARNINGS');
			if ($warnings === false || $warnings->fetch(PDO::FETCH_ASSOC) !== false || !$warnings->closeCursor()) {
				return false;
			}
		}
		return true;
	}, $connection);
}

/** Bound replay work and serialize consumers of this collector's local queue. */
function poller_output_replay(SQLite3 $spool, $connection = false): bool {
	$deadline = microtime(true) + 10;
	for ($attempt = 0; $attempt < 20 && microtime(true) < $deadline; $attempt++) {
		$spool->exec('BEGIN IMMEDIATE');
		try {
			$result = $spool->query('SELECT * FROM batches ORDER BY id LIMIT 1');
			$batch = $result->fetchArray(SQLITE3_ASSOC);
			$result->finalize();
			if ($batch === false) {
				$spool->exec('COMMIT');
				return true;
			}
			if (!poller_output_deliver($batch, $connection)) {
				$spool->exec('ROLLBACK');
				return false;
			}
			$statement = $spool->prepare('DELETE FROM batches WHERE id=?');
			$statement->bindValue(1, (int) $batch['id'], SQLITE3_INTEGER);
			$result = $statement->execute();
			$result->finalize();
			$statement->close();
			$spool->exec('COMMIT');
		} catch (Throwable $error) {
			$spool->exec('ROLLBACK');
			throw $error;
		}
	}
	return (int) $spool->querySingle('SELECT COUNT(*) FROM batches') === 0;
}
