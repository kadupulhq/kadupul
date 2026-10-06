<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/PresentationMutationEvidence.php';

final class PresentationGraphCreationEvidence
{
    public static function sources(): array
    {
        return array_values(array_unique(array_merge(PresentationMutationEvidence::sources(), array(
            'tests/Helpers/PresentationGraphCreationEvidence.php', 'tests/Fixtures/presentation-graph-creation-native.php',
            'tests/Unit/PresentationGraphCreationNativeCoverageTest.php', 'lib/sort.php', 'graphs_new.php', 'resource/snmp_queries/interface.xml',
        ))));
    }

    public static function markers(string $case): array
    {
        return array('unmodified-graph-creation-module:' . $case, 'actual-graph-creation-render:' . $case,
            'persisted-graph-creation-state:' . $case, 'stored-metadata-preserved:' . $case);
    }

    public static function tables(): array
    {
        return array('host', 'host_template', 'host_graph', 'graph_local', 'graph_templates', 'user_auth', 'user_auth_realm', 'user_auth_perms', 'user_auth_group_perms', 'settings_user','plugin_hooks','plugin_config','user_auth_group_realm','user_auth_group','user_auth_group_members','snmp_query','snmp_query_graph','host_snmp_query','host_snmp_cache');
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
