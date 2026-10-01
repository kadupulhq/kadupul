<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

function upgrade_to_1_2_34()
{
    require_once dirname(__DIR__, 2) . '/lib/data_source_profile_integrity.php';
    foreach (data_source_profile_reference_triggers() as $name => $definition) {
        $existing = db_fetch_assoc_prepared(
            'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            array($name)
        );
        if (!is_array($existing)) {
            throw new \RuntimeException('Unable to inspect Data Source Profile reference guards.');
        }
        if (!$existing) {
            db_install_execute($definition['sql']);
        }
    }
    if (!data_source_profile_reference_guards_available()) {
        throw new \RuntimeException('Data Source Profile reference guards could not be installed. Verify InnoDB tables and TRIGGER privileges and rerun the upgrade.');
    }

    if (!db_index_exists('data_template_data', 'data_source_profile_id')) {
        db_install_execute('ALTER TABLE data_template_data ADD INDEX data_source_profile_id (data_source_profile_id)');
    }
    if (!data_source_profile_reference_index_available()) {
        throw new \RuntimeException('Data Source Profile reference index is missing or incompatible. Verify a full, nonunique, visible leading data_source_profile_id index and rerun the upgrade.');
    }
}
