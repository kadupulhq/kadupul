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
    string $prefix = 'kadupul_profile_reference'
): array {
    foreach ([$profiles, $data, $prefix] as $identifier) {
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
            'event' => $event,
            'timing' => $timing,
            'body' => $body,
            'sql' => "CREATE TRIGGER `$name` $timing $event ON `$data` FOR EACH ROW $body",
        ];
    }

    return $definitions;
}

/** A missing or modified guard makes physical profile deletion unsafe. */
function data_source_profile_reference_guards_available(
    string $profiles = 'data_source_profiles',
    string $data = 'data_template_data',
    string $prefix = 'kadupul_profile_reference',
    string $rra = 'data_source_profiles_rra',
    string $cf = 'data_source_profiles_cf'
): bool {
    $definitions = data_source_profile_reference_triggers($profiles, $data, $prefix);
    $engines = db_fetch_assoc_prepared(
        'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?, ?, ?)',
        [$profiles, $data, $rra, $cf]
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
        'SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT
        FROM information_schema.TRIGGERS
        WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?
        AND TRIGGER_NAME IN (?, ?)',
        array_merge([$data], array_keys($definitions))
    );
    if (!is_array($rows) || count($rows) !== count($definitions)) {
        return false;
    }
    foreach ($rows as $row) {
        $definition = $definitions[$row['TRIGGER_NAME'] ?? ''] ?? null;
        if (!$definition || ($row['ACTION_TIMING'] ?? '') !== $definition['timing']
            || ($row['EVENT_MANIPULATION'] ?? '') !== $definition['event']
            || preg_replace('/\s+/', ' ', trim($row['ACTION_STATEMENT'] ?? ''))
                !== preg_replace('/\s+/', ' ', trim($definition['body']))) {
            return false;
        }
    }

    return true;
}

/** Copy the parent catalog before either collector replication path writes children. */
function replicate_data_source_profile_parents(PDO $connection, array $data): bool
{
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
    try {
        $profiles = db_fetch_assoc_prepared(
            'SELECT * FROM data_source_profiles WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            array_values($ids)
        );
        if (!is_array($profiles) || count($profiles) !== count($ids)) {
            return false;
        }
        $definitions = ['data_source_profiles' => $profiles];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        foreach (['data_source_profiles_rra', 'data_source_profiles_cf'] as $table) {
            $rows = db_fetch_assoc_prepared("SELECT * FROM $table WHERE data_source_profile_id IN ($placeholders)", array_values($ids));
            if (!is_array($rows)) {
                return false;
            }
            $delivered = array_unique(array_map(static fn($row) => (int) ($row['data_source_profile_id'] ?? 0), $rows));
            if (array_diff($ids, $delivered)) {
                return false;
            }
            $definitions[$table] = $rows;
        }
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
        cacti_log('ERROR: Unable to replicate profile parents: ' . $error->getMessage(), false, 'REPLICATE');
        return false;
    }
}
