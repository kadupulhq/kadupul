<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/** The columns fix_mediumint.php widened: these by name, then the shared names anywhere else. */
final class IdColumns
{
    /** @var array<string, list<string>> table => columns, in the original's order */
    public const array KNOWN = [
        'data_input_data' => ['data_template_data_id'],
        'data_template_data' => ['id', 'local_data_template_data_id', 'local_data_id'],
        'data_template_rrd' => ['id', 'local_data_template_rrd_id', 'local_data_id'],
        'graph_local' => ['id'],
        'data_local' => ['id'],
        'data_source_purge_action' => ['local_data_id'],
        'data_source_purge_temp' => ['local_data_id'],
        'data_source_stats_daily' => ['local_data_id'],
        'data_source_stats_hourly' => ['local_data_id'],
        'data_source_stats_hourly_cache' => ['local_data_id'],
        'data_source_stats_hourly_last' => ['local_data_id'],
        'data_source_stats_monthly' => ['local_data_id'],
        'data_source_stats_weekly' => ['local_data_id'],
        'data_source_stats_yearly' => ['local_data_id'],
        'graph_templates_graph' => ['id', 'local_graph_id', 'local_graph_template_graph_id'],
        'graph_template_input_defs' => ['graph_template_item_id'],
        'graph_templates_item' => ['id', 'local_graph_template_item_id', 'local_graph_id', 'task_item_id'],
        'graph_tree_items' => ['local_graph_id'],
        'poller_item' => ['local_data_id'],
        'poller_output' => ['local_data_id'],
        'poller_output_boost' => ['local_data_id'],
        'poller_output_realtime' => ['local_data_id'],
        'settings_tree' => ['graph_tree_item_id'],
        'snmp_query_graph_rrd' => ['data_template_rrd_id'],
    ];

    /** Checked in every other table. A named column that needed widening, other than id or an auto-increment, joins them. */
    public const array SHARED = ['graph_id', 'data_id'];
}
