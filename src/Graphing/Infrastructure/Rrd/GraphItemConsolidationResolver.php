<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Rrd;

/** Resolve the consolidation function used by each graph item and its GPRINTs. */
final class GraphItemConsolidationResolver
{
    /** Assign one legacy consolidation reference while graph items are processed in order.
     *
     * Graph items are processed in their original sequence because a GPRINT
     * inherits the preceding data item's consolidation function when possible.
     * The caller keeps each resolution beside its path check and DEF creation,
     * preserving the legacy RRDtool call order.
     *
     * @param array<string, mixed> $graph_item Graph item being processed, updated with cf_reference.
     * @param array<string, array<int|string, mixed>> $last_graph_cf Last CF by source and template item ID, updated by reference.
     * @param int $rra_seconds Selected archive resolution in seconds.
     *
     * @return mixed Consolidation function for this graph item.
     */
    public function assignReference(array &$graph_item, array &$last_graph_cf, $rra_seconds)
    {
        /* mimic the old behavior: LINE[123], AREA and STACK items use the CF specified in the graph item */
        switch ($graph_item['graph_type_id']) {
            case GRAPH_ITEM_TYPE_LINE1:
            case GRAPH_ITEM_TYPE_LINE2:
            case GRAPH_ITEM_TYPE_LINE3:
            case GRAPH_ITEM_TYPE_LINESTACK:
            case GRAPH_ITEM_TYPE_TIC:
            case GRAPH_ITEM_TYPE_AREA:
            case GRAPH_ITEM_TYPE_STACK:
                $graph_cf = generate_graph_best_cf($graph_item['local_data_id'], $graph_item['consolidation_function_id'], $rra_seconds);

                /* remember the last CF for this data source for use with GPRINT
                 * if e.g. an AREA/AVERAGE and a LINE/MAX is used, depending on sequence */
                $last_graph_cf[$graph_item['data_source_name']][$graph_item['local_data_template_rrd_id']] = $graph_cf;

                break;
            case GRAPH_ITEM_TYPE_GPRINT:
                /* GPRINT's configured CF is an aggregation function, so infer the
                 * data item's consolidation function from the preceding graph items. */
                if (isset($last_graph_cf[$graph_item['data_source_name']][$graph_item['local_data_template_rrd_id']])) {
                    $graph_cf = $last_graph_cf[$graph_item['data_source_name']][$graph_item['local_data_template_rrd_id']];
                } else {
                    $graph_cf = generate_graph_best_cf($graph_item['local_data_id'], $graph_item['consolidation_function_id'], $rra_seconds);
                }

                break;
            case GRAPH_ITEM_TYPE_GPRINT_AVERAGE:
            case GRAPH_ITEM_TYPE_GPRINT_LAST:
            case GRAPH_ITEM_TYPE_GPRINT_MAX:
            case GRAPH_ITEM_TYPE_GPRINT_MIN:
                $graph_cf = $graph_item['consolidation_function_id'];

                break;
            default:
                $graph_cf = generate_graph_best_cf($graph_item['local_data_id'], $graph_item['consolidation_function_id'], $rra_seconds);

                break;
        }

        $graph_item['cf_reference'] = $graph_cf;

        return $graph_cf;
    }
}
