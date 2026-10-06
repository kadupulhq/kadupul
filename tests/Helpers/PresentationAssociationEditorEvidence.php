<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/PresentationGraphCreationEvidence.php';

final class PresentationAssociationEditorEvidence
{
    public static function sources(): array
    {
        return array_values(array_unique(array_merge(PresentationGraphCreationEvidence::sources(), array(
            'tests/Helpers/PresentationAssociationEditorEvidence.php',
            'tests/Fixtures/presentation-association-editors-native.php',
            'tests/Unit/PresentationAssociationEditorNativeCoverageTest.php',
        ))));
    }

    public static function markers(string $case): array
    {
        return array('original-association-module:' . $case, 'actual-editor-dom:' . $case,
            'canonical-association-joins:' . $case, 'unchanged-persisted-records:' . $case);
    }

    public static function cases(): array
    {
        return array('query-edit-xml', 'query-edit-missing-xml', 'query-new',
            'query-association-edit', 'query-association-new', 'color-confirm', 'color-edit', 'color-new');
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
