<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Persistence;

/**
 * Canonical trigger definitions guarding data_source_profiles parent rows and
 * their child references, matching lib/data_source_profile_integrity.php.
 */
final class DataSourceProfileReferenceTriggers
{
    /**
     * @return array<string, array{table: string, event: string, timing: string, body: string, sql: string}>
     */
    public static function definitions(
        string $profiles = 'data_source_profiles',
        string $data = 'data_template_data',
        string $prefix = 'kadupul_profile_reference',
        string $rra = 'data_source_profiles_rra',
        string $cf = 'data_source_profiles_cf',
    ): array {
        foreach ([$profiles, $data, $prefix, $rra, $cf] as $identifier) {
            if (preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,50}$/', $identifier) !== 1) {
                throw new \InvalidArgumentException('Invalid profile guard identifier.');
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

        $deleteBody = (string) preg_replace('/    IF parent_profile IS NULL THEN.*?    END IF;\n/s', '', $insert);
        $delete = str_replace('NEW.', 'OLD.', $deleteBody);

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
            foreach (['INSERT' => $insert, 'UPDATE' => $update, 'DELETE' => $delete] as $event => $body) {
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
}
