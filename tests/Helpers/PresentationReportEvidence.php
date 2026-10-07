<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/PresentationMutationEvidence.php';

final class PresentationReportEvidence
{
    public static function sources(): array
    {
        return array_values(array_unique(array_merge(PresentationMutationEvidence::sources(), array(
            'tests/Helpers/PresentationReportEvidence.php', 'tests/Fixtures/presentation-reports-native.php',
            'tests/Unit/PresentationReportNativeCoverageTest.php', 'lib/sort.php',
        ))));
    }

    public static function markers(string $case): array
    {
        return array('unmodified-report-module:' . $case, 'actual-report-outcome:' . $case,
            'persisted-report-state:' . $case, 'adjacent-report-preserved:' . $case);
    }

    public static function tables(): array
    {
        return array('reports', 'reports_items', 'host', 'graph_local', 'graph_templates_graph',
            'graph_templates', 'graph_tree', 'graph_tree_items', 'user_auth_realm');
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
