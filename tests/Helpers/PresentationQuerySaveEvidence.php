<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/PresentationGraphCreationEvidence.php';

final class PresentationQuerySaveEvidence
{
    public static function sources(): array
    {
        return array_values(array_unique(array_merge(PresentationGraphCreationEvidence::sources(), array(
            'tests/Helpers/PresentationQuerySaveEvidence.php',
            'tests/Fixtures/presentation-query-save-native.php',
            'tests/Unit/PresentationQuerySaveNativeCoverageTest.php',
        ))));
    }

    public static function markers(string $case): array
    {
        return array('original-query-module:' . $case, 'actual-query-save-outcome:' . $case,
            'persisted-suggestion-identity:' . $case, 'adjacent-query-records-preserved:' . $case);
    }

    public static function cases(): array
    {
        return array('query-empty-name', 'query-empty-xml', 'association-empty-name',
            'graph-suggestion-save', 'graph-suggestion-empty-text', 'graph-suggestion-empty-field',
            'data-suggestion-save', 'data-suggestion-empty-text', 'data-suggestion-empty-field');
    }

    public static function tables(): array
    {
        return array('data_input', 'graph_templates', 'graph_local', 'data_template', 'data_template_rrd',
            'graph_templates_item', 'colors', 'color_templates', 'data_template_data', 'plugin_hooks', 'plugin_config');
    }

    public static function snapshot(PDO $database): array
    {
        $state = PresentationMutationEvidence::snapshot($database);
        foreach (self::tables() as $table) {
            $state[$table] = $database->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
        }
        return $state;
    }
}
