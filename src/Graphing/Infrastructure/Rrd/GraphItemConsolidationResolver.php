<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Rrd;

/** Resolve the consolidation function used by each graph item and its GPRINTs. */
final class GraphItemConsolidationResolver
{
    /** Assign the legacy consolidation reference to each graph item.
     *
     * Graph items are processed in their original sequence because a GPRINT
     * inherits the preceding data item's consolidation function when possible.
     *
     * @param array<int|string, array<string, mixed>> $graph_items Ordered graph items, updated with cf_reference.
     * @param int $rra_seconds Selected archive resolution in seconds.
     *
     * @return array<string, array<int|string, mixed>> Last graph CF by data-source name and template item ID.
     */
    public function assignReferences(array &$graph_items, $rra_seconds): array
    {
        $last_graph_cf = array();
        if (cacti_sizeof($graph_items)) {
            foreach ($graph_items as $key => $graph_item) {
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
                        $graph_items[$key]['cf_reference'] = $graph_cf;

                        break;
                    case GRAPH_ITEM_TYPE_GPRINT:
                        /* GPRINT's configured CF is an aggregation function, so infer the
                         * data item's consolidation function from the preceding graph items. */
                        if (isset($last_graph_cf[$graph_item['data_source_name']][$graph_item['local_data_template_rrd_id']])) {
                            $graph_cf = $last_graph_cf[$graph_item['data_source_name']][$graph_item['local_data_template_rrd_id']];
                            $graph_items[$key]['cf_reference'] = $graph_cf;
                        } else {
                            $graph_cf = generate_graph_best_cf($graph_item['local_data_id'], $graph_item['consolidation_function_id'], $rra_seconds);
                            $graph_items[$key]['cf_reference'] = $graph_cf;
                        }

                        break;
                    case GRAPH_ITEM_TYPE_GPRINT_AVERAGE:
                    case GRAPH_ITEM_TYPE_GPRINT_LAST:
                    case GRAPH_ITEM_TYPE_GPRINT_MAX:
                    case GRAPH_ITEM_TYPE_GPRINT_MIN:
                        $graph_cf = $graph_item['consolidation_function_id'];
                        $graph_items[$key]['cf_reference'] = $graph_cf;

                        break;
                    default:
                        $graph_cf = generate_graph_best_cf($graph_item['local_data_id'], $graph_item['consolidation_function_id'], $rra_seconds);
                        $graph_items[$key]['cf_reference'] = $graph_cf;

                        break;
                }
            }
        }

        return $last_graph_cf;
    }
}
