<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
*/

declare(strict_types=1);

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
	$problem = poller_output_spool_problem($stat, 'directory', $directory, 0040000, $windows ? null : posix_geteuid());
	if ($problem !== null) {
		poller_output_spool_log($problem);
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
	$problem = poller_output_spool_problem($stat, 'file', $path, 0100000, $windows ? null : posix_geteuid());
	if ($problem === null && $stat['nlink'] !== 1) {
		$problem = 'ERROR: Poller output spool file ' . $path . ' has ' . $stat['nlink'] . ' hard links; it must have one. Stop the PHP poller and investigate before removing the extra links. Collection will not start until this is fixed.';
	}
	if ($problem !== null) {
		poller_output_spool_log($problem);
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

/**
 * Describe why a spool directory or file cannot be used, or return null.
 * $euid is null on Windows, where the operator-managed ACL applies instead.
 * The most common cause is a poller run as root that created the directory.
 */
function poller_output_spool_problem(array|false $stat, string $kind, string $path, int $type, ?int $euid): ?string {
	$label = 'Poller output spool ' . $kind . ' ' . $path;
	if ($stat === false) {
		return 'ERROR: ' . $label . ' cannot be read. Check that its parent exists and that the poller account can open it. Collection will not start until this is fixed.';
	}
	if (($stat['mode'] & 0170000) !== $type) {
		return 'ERROR: ' . $label . ' is not a regular ' . ($kind === 'directory' ? 'directory' : 'file') . '. Replace it with one owned by the poller account. Collection will not start until this is fixed.';
	}
	if ($euid === null) {
		return null;
	}
	if ($stat['uid'] !== $euid) {
		return 'ERROR: ' . $label . ' is owned by ' . poller_output_spool_user($stat['uid']) . ', but the PHP poller runs as ' . poller_output_spool_user($euid)
			. '. This usually follows a poller run as root. Stop the poller, then run "chown -R ' . poller_output_spool_user($euid, true) . ' ' . ($kind === 'directory' ? $path : dirname($path))
			. '" and keep the directory at mode 0700. Do not delete pending queue files. Collection will not start until this is fixed.';
	}
	if (($stat['mode'] & 0077) !== 0) {
		return 'ERROR: ' . $label . ' has mode ' . sprintf('%04o', $stat['mode'] & 07777) . '; other accounts must have no access. Run "chmod ' . ($kind === 'directory' ? '0700' : '0600') . ' ' . $path . '". Collection will not start until this is fixed.';
	}
	return null;
}

/** An account name with its uid, or the uid alone when no name is known. */
function poller_output_spool_user(int $uid, bool $name_only = false): string {
	$entry = function_exists('posix_getpwuid') ? @posix_getpwuid($uid) : false;
	$name  = is_array($entry) && isset($entry['name']) && $entry['name'] !== '' ? $entry['name'] : '';
	if ($name_only) {
		return $name !== '' ? $name : (string) $uid;
	}
	return $name !== '' ? $name . ' (uid ' . $uid . ')' : 'uid ' . $uid;
}

function poller_output_spool_log(string $message): void {
	if (function_exists('cacti_log')) {
		cacti_log($message, true, 'POLLER');
	}
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
	$parameters = poller_output_decode($batch);
	if ($parameters === false) {
		return false;
	}
	$tables = array('poller_output');
	if ($batch['redirect']) {
		$tables[] = 'poller_output_boost';
	}
	$values = implode(',', array_fill(0, intdiv(count($parameters), 4), '(?,?,FROM_UNIXTIME(?),?)'));
	return db_atomic($tables, static function (PDO $db) use ($tables, $values, $parameters): bool {
		foreach ($tables as $table) {
			/* INSERT IGNORE as in 1.2.31: a duplicate key keeps the stored row,
			 * and an over-width or invalid value is stored as the database
			 * adjusts it instead of stopping collection. */
			$sql = 'INSERT IGNORE INTO ' . $table . ' (local_data_id,rrd_name,time,output) VALUES ' . $values;
			if (!db_atomic_execute($db, $sql, $parameters)) {
				return false;
			}
			// MySQL cannot prepare SHOW WARNINGS: PDO's fallback overwrites the
			// insert diagnostics with error 1295. Use the text protocol here only.
			$emulated = $db->getAttribute(PDO::ATTR_EMULATE_PREPARES);
			try {
				if (!$db->setAttribute(PDO::ATTR_EMULATE_PREPARES, true)) {
					return false;
				}
				$warnings = $db->query('SHOW WARNINGS');
				if ($warnings === false) {
					return false;
				}
				$codes = array();
				while (($warning = $warnings->fetch(PDO::FETCH_ASSOC)) !== false) {
					$code = (int) ($warning['Code'] ?? 0);
					if ($code !== 1062) {
						$codes[$code] = ($codes[$code] ?? 0) + 1;
					}
				}
				if (!$warnings->closeCursor()) {
					return false;
				}
			} finally {
				if (!$db->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulated)) {
					throw new RuntimeException('Could not restore database prepare mode.');
				}
			}
			if ($codes) {
				/* codes only: warning text can quote collected values */
				$list = array();
				foreach ($codes as $code => $count) {
					$list[] = $code . ' x' . $count;
				}
				cacti_log('WARNING: Poller output was stored in ' . $table . ' with database warnings (codes ' . implode(', ', $list) . '), usually a value or name longer than its column, which was truncated as in 1.2.31.', false, 'POLLER');
			}
		}
		return true;
	}, $connection);
}

/**
 * Decode a queued batch into insert parameters, or false when the payload is
 * malformed. A malformed batch can never be delivered, so replay moves it to
 * the quarantine table instead of retrying it.
 */
function poller_output_decode(array $batch): array|false {
	try {
		$rows = json_decode((string) ($batch['payload'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
	} catch (JsonException $error) {
		return false;
	}
	if (!is_array($rows) || !$rows || count($rows) > 2002 || !isset($batch['time'])) {
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
	return $parameters;
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
			if (poller_output_decode($batch) === false) {
				/* never retried: keep it for investigation and continue */
				$spool->exec('CREATE TABLE IF NOT EXISTS quarantine (id INTEGER PRIMARY KEY, time INTEGER, redirect INTEGER, payload BLOB, quarantined INTEGER NOT NULL)');
				$statement = $spool->prepare('INSERT INTO quarantine (id,time,redirect,payload,quarantined) VALUES (?,?,?,?,?)');
				$statement->bindValue(1, (int) $batch['id'], SQLITE3_INTEGER);
				$statement->bindValue(2, $batch['time'], is_int($batch['time']) ? SQLITE3_INTEGER : SQLITE3_TEXT);
				$statement->bindValue(3, $batch['redirect'], is_int($batch['redirect']) ? SQLITE3_INTEGER : SQLITE3_TEXT);
				$statement->bindValue(4, $batch['payload'], SQLITE3_BLOB);
				$statement->bindValue(5, time(), SQLITE3_INTEGER);
				$result = $statement->execute();
				$result->finalize();
				$statement->close();
				poller_output_spool_log('ERROR: Poller output spool batch ' . (int) $batch['id'] . ' is malformed and was moved to its quarantine table; collection continues.');
			} elseif (!poller_output_deliver($batch, $connection)) {
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
