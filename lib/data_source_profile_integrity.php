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
    string $prefix = 'kadupul_profile_reference'
): bool {
    $definitions = data_source_profile_reference_triggers($profiles, $data, $prefix);
    $engines = db_fetch_assoc_prepared(
        'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?)',
        [$profiles, $data]
    );
    if (!is_array($engines) || count($engines) !== 2) {
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
