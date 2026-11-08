<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Keep cache replacement and index remapping on the checked connection. */
function data_query_connection(): PDO
{
    global $database_sessions, $database_hostname, $database_port, $database_default;
    $connection = $database_sessions["$database_hostname:$database_port:$database_default"] ?? null;
    if (!$connection instanceof PDO) {
        throw new RuntimeException('Data query database connection is unavailable.');
    }
    return $connection;
}

function data_query_statement(PDO $connection, string $sql, array $parameters = array()): PDOStatement
{
    $statement = $connection->prepare($sql);
    if ($statement === false || !$statement->execute($parameters)) {
        throw new RuntimeException('Data query database operation failed.');
    }
    return $statement;
}

/** Serialize complete reindexes without holding row locks during device I/O. */
function data_query_serialized(int $host_id, int $query_id, callable $work): bool
{
    global $database_fail_fast;
    $connection = null;
    $name = null;
    $owned = false;
    $complete = false;
    $prior_fail_fast = $database_fail_fast ?? false;
    try {
        $connection = data_query_connection();
        $database = data_query_statement($connection, 'SELECT DATABASE()')->fetchColumn();
        if (!is_string($database) || $database === '') {
            return false;
        }
        $name = 'reindex.' . substr(hash('sha256', $database . ':' . $host_id . ':' . $query_id), 0, 48);
        if (data_query_statement($connection, 'SELECT IS_USED_LOCK(?)', array($name))->fetchColumn() !== null) {
            return false;
        }
        $owned = (int) data_query_statement($connection, 'SELECT GET_LOCK(?, 0)', array($name))->fetchColumn() === 1;
        if (!$owned) {
            return false;
        }
        // Statement-level reconnection would abandon this connection's ownership.
        $database_fail_fast = true;
        $complete = (bool) $work();
    } catch (Throwable $error) {
        cacti_log('ERROR: Data query failed; reindex must be retried.', false, 'REINDEX');
    } finally {
        $database_fail_fast = $prior_fail_fast;
        if ($owned) {
            try {
                if ((int) data_query_statement($connection, 'SELECT RELEASE_LOCK(?)', array($name))->fetchColumn() !== 1) {
                    $complete = false;
                }
            } catch (Throwable $error) {
                $complete = false;
                cacti_log('ERROR: Data query lock release could not be confirmed.', false, 'REINDEX');
            }
        }
    }
    return $complete;
}

/** No network or plugin work belongs inside this local mutation boundary. */
function data_query_transaction(int $host_id, array $tables, callable $work): mixed
{
    global $database_fail_fast;
    try {
        $connection = data_query_connection();
    } catch (Throwable $error) {
        cacti_log('ERROR: Data query database connection is unavailable.', false, 'REINDEX');
        return false;
    }
    $owned = !$connection->inTransaction();
    $savepoint = 'reindex_' . bin2hex(random_bytes(8));
    $started = false;
    $complete = false;
    $prior_fail_fast = $database_fail_fast ?? false;
    try {
        $database_fail_fast = true;
        $started = $owned ? $connection->beginTransaction()
            : $connection->exec('SAVEPOINT ' . $savepoint) !== false;
        if (!$started) {
            throw new RuntimeException('Data query transaction could not start.');
        }
        foreach (array_unique(array_merge(array('host'), $tables)) as $table) {
            if (!in_array($table, array('host', 'host_snmp_cache', 'host_snmp_query', 'data_local', 'data_input_data', 'data_template_data', 'data_input_fields', 'graph_local', 'graph_templates_item', 'data_template_rrd'), true)) {
                throw new RuntimeException('Unexpected data query table.');
            }
            // Hold metadata locks and reject temporary shadows before trusting
            // SHOW TABLE STATUS, which describes the permanent table only.
            data_query_statement($connection, 'SELECT 1 FROM `' . $table . '` LIMIT 0')->closeCursor();
            $definition = data_query_statement($connection, 'SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM);
            $status = data_query_statement($connection, "SHOW TABLE STATUS WHERE Name = '$table'")->fetch(PDO::FETCH_ASSOC);
            if (!is_array($definition) || preg_match('/\ACREATE TABLE\s/i', $definition[1]) !== 1 || ($status['Engine'] ?? null) !== 'InnoDB') {
                throw new RuntimeException('Data query tables must be permanent InnoDB tables.');
            }
        }
        // The parent exists even when the cache is empty. Both replacement
        // and remapping take this lock before inspecting or changing cache rows.
        if (data_query_statement($connection, 'SELECT id FROM host WHERE id = ? FOR UPDATE', array($host_id))->fetchColumn() === false) {
            throw new RuntimeException('Data query host no longer exists.');
        }
        $result = $work($connection);
        if ($result === false || !$connection->inTransaction()) {
            throw new RuntimeException('Data query mutation did not complete.');
        }
        $complete = $owned ? $connection->commit()
            : $connection->exec('RELEASE SAVEPOINT ' . $savepoint) !== false;
        return $complete ? $result : false;
    } catch (Throwable $error) {
        cacti_log('ERROR: Data query mutation failed; reindex must be retried.', false, 'REINDEX');
        return false;
    } finally {
        try {
            if ($started && !$complete && $connection->inTransaction()) {
                if ($owned) {
                    if (!$connection->rollBack()) {
                        throw new RuntimeException('Rollback failed.');
                    }
                } elseif ($connection->exec('ROLLBACK TO SAVEPOINT ' . $savepoint) === false || $connection->exec('RELEASE SAVEPOINT ' . $savepoint) === false) {
                    throw new RuntimeException('Savepoint rollback failed.');
                }
            }
        } catch (Throwable $cleanup_error) {
            cacti_log('ERROR: Data query rollback could not be confirmed.', false, 'REINDEX');
        } finally {
            $database_fail_fast = $prior_fail_fast;
        }
    }
}
