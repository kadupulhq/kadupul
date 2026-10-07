<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/PresentationMutationEvidence.php';

final class PresentationSettingsEvidence
{
    public static function sources(): array
    {
        return array_values(array_unique(array_merge(PresentationMutationEvidence::sources(), array(
            'tests/Helpers/PresentationSettingsEvidence.php', 'tests/Fixtures/presentation-settings-native.php',
            'tests/Unit/PresentationSettingsNativeCoverageTest.php', 'lib/sort.php', 'tests/Fixtures/presentation-fontconfig-list.sh',
        ))));
    }

    public static function markers(string $case): array
    {
        return array('unmodified-settings-module:' . $case, 'actual-settings-save-outcome:' . $case,
            'persisted-settings-readback:' . $case, 'unrelated-setting-preserved:' . $case);
    }

    public static function tables(): array
    {
        return array('settings','version','poller','snmpagent_cache','plugin_hooks','plugin_config');
    }

    public static function snapshot(PDO $database): array
    {
        $state = array();
        foreach (self::tables() as $table) {
            $state[$table] = $database->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
        }
        return $state;
    }
}
