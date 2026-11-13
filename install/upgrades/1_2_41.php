<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Add centered moments without converting lossy squared sums into a guessed
 * variance. NULL marks history from before this upgrade; the evaluator warms
 * up fresh history. Existing rows and legacy columns remain for rollback.
 */
function upgrade_to_1_2_41(): void
{
    foreach (['sample_mean', 'sample_m2'] as $column) {
        $query = "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alert_baseline_sample' AND COLUMN_NAME = ?";
        $found = db_install_fetch_cell($query, [$column]);
        if ((string) ($found['data'] ?? '') === '0') {
            if (db_install_execute('ALTER TABLE alert_baseline_sample ADD COLUMN ' . $column . ' double DEFAULT NULL') !== DB_STATUS_SUCCESS) {
                throw new RuntimeException('The baseline moment column could not be added. Rerun the upgrade after correcting the database error.');
            }
        }
        $confirmed = db_install_fetch_cell($query . " AND DATA_TYPE = 'double' AND COLUMN_TYPE NOT LIKE '%unsigned%' AND IS_NULLABLE = 'YES' AND (COLUMN_DEFAULT IS NULL OR COLUMN_DEFAULT = 'NULL') AND EXTRA = ''", [$column]);
        if ((string) ($confirmed['data'] ?? '') !== '1') {
            throw new RuntimeException('The baseline moment column differs from its required definition. Review it and rerun the upgrade.');
        }
    }
}
