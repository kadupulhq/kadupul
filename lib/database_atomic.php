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

/** Execute a local operation without committing work owned by its caller. */
function db_atomic(array $tables, callable $operation, $connection = false): bool {
	global $database_sessions, $database_hostname, $database_port, $database_default;

	if ($connection === false) {
		$connection = $database_sessions["$database_hostname:$database_port:$database_default"] ?? null;
	}
	if (!$connection instanceof PDO) {
		return false;
	}

	$started = $complete = false;
	$owned = !$connection->inTransaction();
	$savepoint = 'operation_' . bin2hex(random_bytes(8));
	try {
		$started = $owned ? $connection->beginTransaction() : db_atomic_execute($connection, 'SAVEPOINT ' . $savepoint);
		if (!$started) {
			return false;
		}
		foreach (array_unique($tables) as $table) {
			if (!db_atomic_table($connection, $table)) {
				return false;
			}
		}
		if ($operation($connection) === true && $connection->inTransaction()) {
			$complete = $owned ? $connection->commit() : db_atomic_execute($connection, 'RELEASE SAVEPOINT ' . $savepoint);
		}
	} catch (Throwable $error) {
		// SQL and exception text can contain collected values or credentials.
		cacti_log('ERROR: Database operation failed; changes were not confirmed.', false, 'DBCALL');
	} finally {
		if ($started && !$complete && $connection->inTransaction()) {
			try {
				$rolled_back = $owned ? $connection->rollBack()
					: db_atomic_execute($connection, 'ROLLBACK TO SAVEPOINT ' . $savepoint)
						&& db_atomic_execute($connection, 'RELEASE SAVEPOINT ' . $savepoint);
				if (!$rolled_back) {
					cacti_log('ERROR: Database operation rollback was not confirmed.', false, 'DBCALL');
				}
			} catch (Throwable $error) {
				cacti_log('ERROR: Database operation rollback was not confirmed.', false, 'DBCALL');
			}
		}
	}

	return $complete;
}

/** Never reconnect or retry a single statement inside a logical transaction. */
function db_atomic_execute(PDO $connection, string $sql, array $parameters = []): bool {
	$statement = $connection->prepare($sql);
	return $statement !== false && $statement->execute($parameters) && $statement->closeCursor();
}

/** Inspect the actual table, holding its metadata lock through the mutation. */
function db_atomic_table(PDO $connection, string $table): bool {
	if (preg_match('/\A[a-z_]+\z/D', $table) !== 1 || $connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
		return false;
	}
	if (!db_atomic_execute($connection, 'SELECT 1 FROM `' . $table . '` LIMIT 0')) {
		return false;
	}
	$statement = $connection->query('SHOW CREATE TABLE `' . $table . '`');
	if ($statement === false) {
		return false;
	}
	$definition = $statement->fetch(PDO::FETCH_NUM);
	if (!$statement->closeCursor() || preg_match('/\ACREATE TABLE\s/i', $definition[1] ?? '') !== 1) {
		return false;
	}
	// A table comment can contain ENGINE=InnoDB; inspect structured metadata.
	$statement = $connection->query("SHOW TABLE STATUS WHERE Name = '$table'");
	if ($statement === false) {
		return false;
	}
	$row = $statement->fetch(PDO::FETCH_ASSOC);
	return $statement->closeCursor() && ($row['Name'] ?? null) === $table && ($row['Engine'] ?? null) === 'InnoDB';
}
