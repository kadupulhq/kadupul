<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Guard all SQL writers without rewriting existing zero or orphan references.
 * Locking reads see a concurrent deletion's committed result before permitting
 * a new reference. An unchanged legacy reference remains editable via upsert.
 */
function data_source_profile_reference_triggers(
    string $profiles = 'data_source_profiles',
    string $data = 'data_template_data',
    string $prefix = 'kadupul_profile_reference',
    string $rra = 'data_source_profiles_rra',
    string $cf = 'data_source_profiles_cf'
): array {
    foreach ([$profiles, $data, $prefix, $rra, $cf] as $identifier) {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,50}$/', $identifier)) {
            throw new InvalidArgumentException('Invalid profile guard identifier.');
        }
    }
    $insert = "BEGIN
DECLARE parent_profile BIGINT DEFAULT NULL;
DECLARE CONTINUE HANDLER FOR NOT FOUND SET parent_profile = NULL;
IF NEW.data_source_profile_id <> 0 THEN
    SELECT id INTO parent_profile FROM `$profiles` WHERE id = NEW.data_source_profile_id FOR UPDATE;
    IF parent_profile IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Data Source Profile no longer exists';
    END IF;
END IF;
END";
    $update = "BEGIN
DECLARE parent_profile BIGINT DEFAULT NULL;
DECLARE CONTINUE HANDLER FOR NOT FOUND SET parent_profile = NULL;
IF NEW.data_source_profile_id <> 0 AND NEW.data_source_profile_id <> OLD.data_source_profile_id THEN
    SELECT id INTO parent_profile FROM `$profiles` WHERE id = NEW.data_source_profile_id FOR UPDATE;
    IF parent_profile IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Data Source Profile no longer exists';
    END IF;
END IF;
END";
    $definitions = [];
    foreach (['INSERT' => $insert, 'UPDATE' => $update] as $event => $body) {
        $name = $prefix . '_' . strtolower($event);
        $timing = $event === 'INSERT' ? 'AFTER' : 'BEFORE';
        $definitions[$name] = [
            'table' => $data,
            'event' => $event,
            'timing' => $timing,
            'body' => $body,
            'sql' => "CREATE TRIGGER `$name` $timing $event ON `$data` FOR EACH ROW $body",
        ];
    }

    foreach (['rra' => $rra, 'cf' => $cf] as $kind => $table) {
        foreach (['INSERT' => $insert, 'UPDATE' => $update, 'DELETE' => str_replace('NEW.', 'OLD.', preg_replace('/    IF parent_profile IS NULL THEN.*?    END IF;\n/s', '', $insert))] as $event => $body) {
            $name = $prefix . '_' . $kind . '_' . strtolower($event);
            $timing = $event === 'INSERT' ? 'AFTER' : 'BEFORE';
            $definitions[$name] = [
                'table' => $table,
                'event' => $event,
                'timing' => $timing,
                'body' => $body,
                'sql' => "CREATE TRIGGER `$name` $timing $event ON `$table` FOR EACH ROW $body",
            ];
        }
    }

    return $definitions;
}

/** A missing or modified guard makes physical profile deletion unsafe. */
function data_source_profile_reference_guards_available(
    string $profiles = 'data_source_profiles',
    string $data = 'data_template_data',
    string $prefix = 'kadupul_profile_reference',
    string $rra = 'data_source_profiles_rra',
    string $cf = 'data_source_profiles_cf',
    PDO|false $connection = false
): bool {
    $definitions = data_source_profile_reference_triggers($profiles, $data, $prefix, $rra, $cf);
    $engines = db_fetch_assoc_prepared(
        'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?, ?, ?)',
        [$profiles, $data, $rra, $cf],
        true,
        $connection
    );
    if (!is_array($engines) || count($engines) !== 4) {
        return false;
    }
    foreach ($engines as $table) {
        if (strcasecmp($table['ENGINE'] ?? '', 'InnoDB') !== 0) {
            return false;
        }
    }
    $rows = db_fetch_assoc_prepared(
        'SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT
        FROM information_schema.TRIGGERS
        WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE IN (?,?,?)
        AND TRIGGER_NAME IN (?,?,?,?,?,?,?,?)',
        array_merge([$data, $rra, $cf], array_keys($definitions)),
        true,
        $connection
    );
    if (!is_array($rows) || count($rows) !== count($definitions)) {
        return false;
    }
    foreach ($rows as $row) {
        $definition = $definitions[$row['TRIGGER_NAME'] ?? ''] ?? null;
        if (!$definition || ($row['EVENT_OBJECT_TABLE'] ?? '') !== $definition['table']
            || ($row['ACTION_TIMING'] ?? '') !== $definition['timing']
            || ($row['EVENT_MANIPULATION'] ?? '') !== $definition['event']
            || preg_replace('/\s+/', ' ', trim($row['ACTION_STATEMENT'] ?? ''))
                !== preg_replace('/\s+/', ' ', trim($definition['body']))) {
            return false;
        }
    }

    return true;
}

/** Collector synchronization requires the complete registered profile upgrade. */
function data_source_profile_reference_index_available(PDO|false $connection = false): bool
{
    $rows = db_fetch_assoc_prepared(
        'SELECT * FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? AND SEQ_IN_INDEX = 1',
        ['data_template_data', 'data_source_profile_id'],
        true,
        $connection
    );

    return is_array($rows) && count($rows) === 1
        && (int) ($rows[0]['SEQ_IN_INDEX'] ?? 0) === 1
        && ($rows[0]['COLUMN_NAME'] ?? '') === 'data_source_profile_id'
        && array_key_exists('SUB_PART', $rows[0]) && $rows[0]['SUB_PART'] === null
        && (int) ($rows[0]['NON_UNIQUE'] ?? 0) === 1
        && (!isset($rows[0]['IS_VISIBLE']) || $rows[0]['IS_VISIBLE'] === 'YES')
        && (!isset($rows[0]['IGNORED']) || $rows[0]['IGNORED'] === 'NO');
}

/** Definition writers use the same audited guard catalog. */
function data_source_profile_definition_triggers(string $profiles = 'data_source_profiles', string $rra = 'data_source_profiles_rra', string $cf = 'data_source_profiles_cf', string $prefix = 'kadupul_profile_reference'): array
{
    $all = data_source_profile_reference_triggers($profiles, 'data_template_data', $prefix, $rra, $cf);
    return array_filter($all, static fn($definition) => $definition['table'] !== 'data_template_data');
}

/** Copy the parent catalog before either collector replication path writes children. */
function replicate_data_source_profile_parents(PDO $connection, array $data): bool
{
    global $database_sessions, $database_default, $database_hostname, $database_port;

    $ids = [];
    foreach ($data as $row) {
        $id = $row['data_source_profile_id'] ?? 0;
        if (!is_numeric($id) || (int) $id < 0) {
            return false;
        }
        if ((int) $id > 0) {
            $ids[(int) $id] = (int) $id;
        }
    }
    if (!$ids) {
        return true;
    }
    $source_started = false;
    try {
        $source_connection = $database_sessions["$database_hostname:$database_port:$database_default"] ?? null;
        if (!$source_connection instanceof PDO) {
            throw new RuntimeException('Source profile connection is unavailable.');
        }
        if ($source_connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' && !data_source_profile_reference_guards_available()) {
            throw new RuntimeException('Source profile catalogs require InnoDB and intact definition guards.');
        }
        if (!$source_connection->inTransaction()) {
            if (!db_begin_transaction()) {
                throw new RuntimeException('Source profile snapshot could not be started.');
            }
            $source_started = true;
        }
        $profiles = db_fetch_assoc_prepared(
            'SELECT * FROM data_source_profiles WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id FOR UPDATE',
            array_values($ids)
        );
        if (!is_array($profiles) || count($profiles) !== count($ids)) {
            throw new RuntimeException('Source profile catalog is incomplete.');
        }
        $definitions = ['data_source_profiles' => $profiles];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        foreach (['data_source_profiles_rra', 'data_source_profiles_cf'] as $table) {
            $rows = db_fetch_assoc_prepared("SELECT * FROM $table WHERE data_source_profile_id IN ($placeholders) FOR UPDATE", array_values($ids));
            if (!is_array($rows)) {
                throw new RuntimeException('Source profile definitions could not be read.');
            }
            $delivered = array_unique(array_map(static fn($row) => (int) ($row['data_source_profile_id'] ?? 0), $rows));
            if (array_diff($ids, $delivered)) {
                throw new RuntimeException('Source profile definitions are incomplete.');
            }
            $definitions[$table] = $rows;
        }
        if ($source_started && !db_commit_transaction()) {
            throw new RuntimeException('Source profile snapshot completion was not acknowledged.');
        }
        $source_started = false;
        // Complete schema creation before opening the delivery transaction:
        // MySQL DDL would otherwise commit a partly copied profile.
        foreach ($definitions as $table => $rows) {
            if (!db_table_exists($table, false, $connection)) {
                $definition = db_fetch_row("SHOW CREATE TABLE $table");
                if (!isset($definition['Create Table']) || !db_execute($definition['Create Table'], false, $connection)) {
                    return false;
                }
            }
        }
        if ($connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $engines = db_fetch_assoc_prepared('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?,?)', array_keys($definitions), true, $connection);
            if (!is_array($engines) || count($engines) !== 3 || array_filter($engines, static fn($row) => strcasecmp($row['ENGINE'] ?? '', 'InnoDB') !== 0)) {
                return false;
            }
        }
        if ($connection->inTransaction() || !$connection->beginTransaction()) {
            return false;
        }
        try {
            foreach ($profiles as $profile) {
                if (!isset($profile['id']) || !isset($ids[(int) $profile['id']])
                    || sql_save($profile, 'data_source_profiles', 'id', true, $connection) === false) {
                    throw new RuntimeException('Profile parent delivery failed.');
                }
            }
            foreach (['data_source_profiles_rra', 'data_source_profiles_cf'] as $table) {
                // RRA IDs are shared catalog identities. Do not overwrite a
                // collector row owned by an unrelated profile on collision.
                if ($table === 'data_source_profiles_rra') {
                    foreach ($definitions[$table] as $row) {
                        $existing = db_fetch_assoc_prepared("SELECT * FROM $table WHERE id=? AND data_source_profile_id<>?", [$row['id'], $row['data_source_profile_id']], true, $connection);
                        if (!is_array($existing) || $existing) {
                            throw new RuntimeException('Collector RRA identity conflicts with another profile.');
                        }
                    }
                }
                if (!db_execute_prepared("DELETE FROM $table WHERE data_source_profile_id IN ($placeholders)", array_values($ids), true, $connection)) {
                    throw new RuntimeException('Collector profile definition replacement failed.');
                }
                foreach ($definitions[$table] as $row) {
                    $columns = '`' . implode('`,`', array_keys($row)) . '`';
                    $values = implode(',', array_fill(0, count($row), '?'));
                    if (!db_execute_prepared("INSERT INTO $table ($columns) VALUES ($values)", array_values($row), true, $connection)) {
                        throw new RuntimeException('Collector profile definition delivery failed.');
                    }
                }
            }
            $normalize = static function (array $rows): array {
                $values = array_map(static function ($row) {
                    ksort($row);
                    return json_encode(array_map(static fn($value) => $value === null ? null : (string) $value, $row), JSON_THROW_ON_ERROR);
                }, $rows);
                sort($values);
                return $values;
            };
            foreach ($definitions as $table => $rows) {
                $key = $table === 'data_source_profiles' ? 'id' : 'data_source_profile_id';
                $actual = db_fetch_assoc_prepared("SELECT * FROM $table WHERE $key IN ($placeholders)", array_values($ids), true, $connection);
                if (!is_array($actual) || $normalize($actual) !== $normalize($rows)) {
                    throw new RuntimeException('Collector profile definition verification failed.');
                }
            }
            if (!$connection->commit()) {
                throw new RuntimeException('Collector profile delivery commit failed.');
            }
            return true;
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
    } catch (Throwable $error) {
        if ($source_started) {
            try {
                db_rollback_transaction();
            } catch (Throwable $rollback_error) {
                cacti_log('ERROR: Source profile snapshot rollback failed: ' . $rollback_error->getMessage(), false, 'REPLICATE');
            }
        }
        cacti_log('ERROR: Unable to replicate profile parents: ' . $error->getMessage(), false, 'REPLICATE');
        return false;
    }
}

/** Publish references only after complete catalog delivery, retaining old rows on any refusal. */
function replicate_data_source_profile_children(PDO $connection, array $data, bool $replace, $exclude = false): bool
{
    $started = false;
    try {
        if ($connection->inTransaction() || !data_source_profile_reference_guards_available(connection: $connection) || !replicate_data_source_profile_parents($connection, $data)) {
            throw new RuntimeException('Profile catalog delivery failed');
        }
        if (!db_table_exists('data_template_data', false, $connection)) {
            $schema = db_fetch_row('SHOW CREATE TABLE data_template_data');
            if (!isset($schema['Create Table']) || !db_execute($schema['Create Table'], false, $connection)) {
                throw new RuntimeException('Collector reference schema creation failed');
            }
        }
        $engines = db_fetch_assoc_prepared('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?)', ['data_source_profiles', 'data_template_data'], true, $connection);
        if (!is_array($engines) || count($engines) !== 2 || array_filter($engines, static fn($row) => strcasecmp($row['ENGINE'] ?? '', 'InnoDB') !== 0)) {
            throw new RuntimeException('Collector reference tables are not transactional');
        }
        $columns = db_fetch_assoc('SHOW COLUMNS FROM data_template_data', false, $connection);
        if (!is_array($columns) || !$columns) {
            throw new RuntimeException('Collector reference schema is unavailable');
        }
        $allowed = array_column($columns, 'Field');
        $excluded = $exclude === false ? [] : (is_array($exclude) ? $exclude : [$exclude]);
        $ids = [];
        $names = $data ? array_keys(reset($data)) : [];
        $keys = [];
        foreach ($data as $row) {
            if (!is_array($row) || !$row || array_keys($row) !== $names || array_diff($names, $allowed) || array_filter($row, static fn($value) => $value !== null && !is_scalar($value)) || !isset($row['id']) || filter_var($row['id'], FILTER_VALIDATE_INT) === false || (int) $row['id'] <= 0 || isset($keys[(int) $row['id']])) {
                throw new RuntimeException('Collector reference schema differs from source');
            }
            $keys[(int) $row['id']] = true;
            $id = (int) ($row['data_source_profile_id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        sort($ids);
        if (!$connection->beginTransaction()) {
            throw new RuntimeException('Collector reference transaction could not start');
        }
        $started = true;
        if ($ids) {
            $parents = db_fetch_assoc_prepared('SELECT id FROM data_source_profiles WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id FOR UPDATE', $ids, true, $connection);
            if (!is_array($parents) || array_map('intval', array_column($parents, 'id')) !== $ids) {
                throw new RuntimeException('Collector profile disappeared before reference delivery');
            }
        }
        if ($replace && !db_execute_prepared('DELETE FROM data_template_data', [], false, $connection)) {
            throw new RuntimeException('Collector reference replacement failed');
        }
        $updates = [];
        foreach ($names as $name) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) {
                throw new RuntimeException('Invalid collector reference column');
            }
            if (!in_array($name, $excluded, true)) {
                $updates[] = '`' . $name . '`=VALUES(`' . $name . '`)';
            }
        }
        if ($data && !$replace && !$updates) {
            throw new RuntimeException('Collector reference update has no permitted columns');
        }
        $deliver = static function (array $batch) use ($connection, $names, $updates, $replace, $excluded): void {
            $tuple = '(' . implode(',', array_fill(0, count($names), '?')) . ')';
            $sql = 'INSERT INTO data_template_data (`' . implode('`,`', $names) . '`) VALUES ' . implode(',', array_fill(0, count($batch), $tuple));
            if (!$replace) {
                $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(',', $updates);
            }
            $values = [];
            $expected = [];
            foreach ($batch as $row) {
                array_push($values, ...array_values($row));
                $expected[(int) $row['id']] = $replace ? $row : array_diff_key($row, array_flip($excluded));
            }
            if (!db_execute_prepared($sql, $values, false, $connection)) {
                throw new RuntimeException('Collector reference write was not acknowledged');
            }
            $actual = db_fetch_assoc_prepared('SELECT * FROM data_template_data WHERE id IN (' . implode(',', array_fill(0, count($expected), '?')) . ')', array_keys($expected), true, $connection);
            if (!is_array($actual) || count($actual) !== count($expected)) {
                throw new RuntimeException('Collector reference verification failed');
            }
            foreach ($actual as $row) {
                $key = (int) ($row['id'] ?? 0);
                if (!isset($expected[$key])) {
                    throw new RuntimeException('Collector reference verification returned an unexpected key');
                }
                foreach ($expected[$key] as $column => $value) {
                    if (!array_key_exists($column, $row) || ($value === null ? $row[$column] !== null : (string) $row[$column] !== (string) $value)) {
                        throw new RuntimeException('Collector reference differs from source');
                    }
                }
                unset($expected[$key]);
            }
        };
        $batch = [];
        $bytes = 0;
        $payloadLimit = 1048576 - 3 * strlen(implode('`,`', $names)) - 128;
        $rowLimit = $names ? min(1000, intdiv(60000, count($names))) : 1000;
        foreach ($data as $row) {
            // Bound values as well as rows/parameters; allow encoding/protocol
            // overhead without building a potentially huge SQL payload.
            $rowBytes = array_sum(array_map(static fn($value) => 32 + 2 * strlen((string) $value), $row));
            if ($rowLimit < 1 || $rowBytes > $payloadLimit) {
                throw new RuntimeException('Collector reference row exceeds the batch payload limit');
            }
            if ($batch && (count($batch) >= $rowLimit || $bytes + $rowBytes > $payloadLimit)) {
                $deliver($batch);
                $batch = [];
                $bytes = 0;
            }
            $batch[] = $row;
            $bytes += $rowBytes;
        }
        if ($batch) {
            $deliver($batch);
        }
        if (!$connection->commit()) {
            throw new RuntimeException('Collector reference commit was not acknowledged');
        }
        return true;
    } catch (Throwable $error) {
        if ($started && $connection->inTransaction()) {
            try {
                $connection->rollBack();
            } catch (Throwable $rollbackError) {
                cacti_log('ERROR: Collector reference rollback failed: ' . $rollbackError->getMessage(), false, 'REPLICATE');
            }
        }
        cacti_log('ERROR: Profile delivery failed; existing collector data-source definitions were retained. ' . $error->getMessage(), false, 'REPLICATE');
        return false;
    }
}

/** Serialize an editor's existing-parent mutation with physical deletion. */
function begin_data_source_profile_mutation(int $id): bool
{
    if (!db_begin_transaction()) {
        return false;
    }
    try {
        if ($id > 0) {
            $rows = db_fetch_assoc_prepared('SELECT id FROM data_source_profiles WHERE id=? FOR UPDATE', [$id]);
            if (!is_array($rows) || count($rows) !== 1 || (int) $rows[0]['id'] !== $id) {
                throw new RuntimeException('Data Source Profile no longer exists.');
            }
        }
        return true;
    } catch (Throwable $error) {
        finish_data_source_profile_mutation(false);
        cacti_log('ERROR: Profile mutation refused: ' . $error->getMessage(), false, 'WEBUI');
        return false;
    }
}

/** Report success only after commit; tolerate server-aborted transactions. */
function finish_data_source_profile_mutation(bool $successful): bool
{
    if ($successful) {
        try {
            if (db_commit_transaction()) {
                return true;
            }
        } catch (Throwable $error) {
            cacti_log('ERROR: Profile mutation commit failed: ' . $error->getMessage(), false, 'WEBUI');
        }
    }
    try {
        db_rollback_transaction();
    } catch (Throwable $error) {
        cacti_log('ERROR: Profile mutation rollback unavailable: ' . $error->getMessage(), false, 'WEBUI');
    }
    return false;
}
