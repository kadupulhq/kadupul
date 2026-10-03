<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2004-2026 The Cacti Group
// SPDX-License-Identifier: GPL-2.0-or-later
// Pinned original output oracle: 5ad6240bb454cfd475291ed5e9fbe646aba954ac.

function aggregate_percentile_original($member_graphs, $skipped_items, $local_graph_id, $_total, $_total_type)
{
    $special_comments  = null;
    $special_hrules    = null;
    $graph_template_id = 0;

    $agg_info = db_fetch_row_prepared(
        'SELECT *
		FROM aggregate_graphs
		WHERE local_graph_id = ?',
        array($local_graph_id)
    );

    if (cacti_sizeof($agg_info)) {
        $comments_hrules = db_fetch_assoc_prepared(
            'SELECT *
			FROM graph_templates_item
			WHERE graph_type_id IN (?, ?)
			AND graph_template_id = ?
			AND local_graph_id = 0' .
            (cacti_sizeof($skipped_items) ? ' AND sequence NOT IN (' . implode(',', $skipped_items) . ')' : '') . '
			AND (text_format != "" || value != "")
			ORDER BY sequence ASC',
            array(GRAPH_ITEM_TYPE_COMMENT, GRAPH_ITEM_TYPE_HRULE, $agg_info['graph_template_id'])
        );

        $graph_template_id = $agg_info['graph_template_id'];
    } else {
        if (cacti_sizeof($member_graphs)) {
            $template_graph[] = $member_graphs[0];
        } else {
            $template_graph   = array();
        }

        $comments_hrules = db_fetch_assoc('SELECT *
			FROM graph_templates_item
			WHERE graph_type_id IN(' . GRAPH_ITEM_TYPE_COMMENT . ',' . GRAPH_ITEM_TYPE_HRULE . ')' .
            (cacti_sizeof($template_graph) ? ' AND ' . array_to_sql_or($template_graph, 'local_graph_id') : '') .
            (cacti_sizeof($skipped_items) ? ' AND sequence NOT IN(' . implode(',', $skipped_items) . ')' : '') . '
			AND (text_format != "" || value != "")
			ORDER BY local_graph_id, sequence ASC');

        if (cacti_sizeof($comments_hrules)) {
            $graph_template_id = $comments_hrules[0]['graph_template_id'];
        }
    }

    $next_item_sequence = db_fetch_cell_prepared(
        'SELECT MAX(sequence)
		FROM graph_templates_item
		WHERE local_graph_id = ?',
        array($local_graph_id)
    );

    if (cacti_sizeof($comments_hrules)) {
        foreach ($comments_hrules as $item) {
            switch ($item['graph_type_id']) {
                case GRAPH_ITEM_TYPE_COMMENT:
                    if (!isset($special_comments[$item['text_format'] . '|' . $item['value'] . '|' . $item['task_item_id']])) {
                        if (preg_match('/(:bits:|:bytes:)/', $item['text_format'])) {
                            $special_comments[$item['text_format'] . '|' . $item['value'] . '|' . $item['task_item_id']] = true;

                            $parts = explode('|', $item['text_format']);

                            if (isset($parts[1])) {
                                $pparts = explode(':', $parts[1]);

                                if (isset($pparts[3])) {
                                    if ($_total_type == AGGREGATE_TOTAL_TYPE_ALL) {
                                        $pparts[3] = str_replace('current', 'aggregate_sum', $pparts[3]);
                                        $pparts[3] = str_replace('max', 'aggregate_sum_peak', $pparts[3]);
                                    } elseif ($_total_type == AGGREGATE_TOTAL_TYPE_SIMILAR) {
                                        $pparts[3] = str_replace('current', 'aggregate_current', $pparts[3]);
                                        $pparts[3] = str_replace('max', 'aggregate_current_peak', $pparts[3]);
                                    } else {
                                        cacti_log(__FUNCTION__ . ' unhandled total_type ' . $_total_type . ' for pparts[3] in text_format', true, 'AGGREGATE', POLLER_VERBOSITY_DEBUG);
                                    }

                                    switch ($pparts[3]) {
                                        case 'current':
                                            $new_ppart = 'current';
                                            break;
                                        case 'total':
                                            $new_ppart = 'aggregate_sum';
                                            break;
                                        case 'max':
                                            $new_ppart = 'max';
                                            break;
                                        case 'total_peak':
                                            $new_ppart = 'total_peak';
                                            break;
                                        case 'all_max_current':
                                        case 'all_max_peak':
                                        case 'aggregate_max':
                                            $new_ppart = 'aggregate_peak';
                                            break;
                                        case 'aggregate_sum':
                                        case 'aggregate_sum_peak':
                                        case 'aggregate_current':
                                        case 'aggregate_current_peak':
                                        case 'aggregate':
                                        case 'aggregate_peak':
                                            $new_ppart = $pparts[3];
                                            break;
                                        default:
                                            $new_ppart = 'max';
                                            break;
                                    }

                                    $pparts[3] = $new_ppart;

                                    $parts[1] = implode(':', $pparts);
                                    $item['text_format'] = implode('|', $parts);
                                }
                            }

                            db_execute_prepared(
                                "INSERT INTO graph_templates_item
								(graph_template_id, local_graph_id, task_item_id, graph_type_id, consolidation_function_id, text_format, value, hard_return, gprint_id, sequence)
								VALUES (?, ?, ?, ?, 1, ?, '', ?, 2, ?)",
                                array(
                                    $graph_template_id,
                                    $local_graph_id,
                                    $item['task_item_id'],
                                    GRAPH_ITEM_TYPE_COMMENT,
                                    $item['text_format'],
                                    $item['hard_return'],
                                    $next_item_sequence++
                                )
                            );
                        }
                    }

                    break;
                case GRAPH_ITEM_TYPE_HRULE:
                    if (!isset($special_hrules[$item['text_format'] . '|' . $item['value'] . '|' . $item['task_item_id']])) {
                        if (preg_match('/(:bits:|:bytes:)/', $item['value'])) {
                            $special_hrules[$item['text_format'] . '|' . $item['value'] . '|' . $item['task_item_id']] = true;

                            $parts = explode('|', $item['value']);
                            if (isset($parts[1])) {
                                $pparts = explode(':', $parts[1]);

                                if (isset($pparts[3])) {
                                    if ($_total_type == AGGREGATE_TOTAL_TYPE_ALL) {
                                        $pparts[3] = str_replace('current', 'aggregate_sum', $pparts[3]);
                                        $pparts[3] = str_replace('max', 'aggregate_sum_peak', $pparts[3]);
                                    } elseif ($_total_type == AGGREGATE_TOTAL_TYPE_SIMILAR) {
                                        $pparts[3] = str_replace('current', 'aggregate_current', $pparts[3]);
                                        $pparts[3] = str_replace('max', 'aggregate_current_peak', $pparts[3]);
                                    } else {
                                        cacti_log(__FUNCTION__ . ' unhandled total_type ' . $_total_type . ' for pparts[3] in value', true, 'AGGREGATE', POLLER_VERBOSITY_DEBUG);
                                    }

                                    switch ($pparts[3]) {
                                        case 'current':
                                            $new_ppart = 'current';
                                            break;
                                        case 'total':
                                            $new_ppart = 'aggregate_sum';
                                            break;
                                        case 'max':
                                            $new_ppart = 'max';
                                            break;
                                        case 'total_peak':
                                            $new_ppart = 'total_peak';
                                            break;
                                        case 'all_max_current':
                                        case 'all_max_peak':
                                        case 'aggregate_max':
                                            $new_ppart = 'aggregate_peak';
                                            break;
                                        case 'aggregate_peak':
                                        case 'aggregate_sum':
                                        case 'aggregate_sum_peak':
                                        case 'aggregate_current':
                                        case 'aggregate_current_peak':
                                        case 'aggregate':
                                            $new_ppart = $pparts[3];
                                            break;
                                        default:
                                            $new_ppart = 'max';
                                            break;
                                    }

                                    $pparts[3] = $new_ppart;

                                    $parts[1] = implode(':', $pparts);
                                    $item['value'] = implode('|', $parts);
                                }
                            }

                            // add an empty line before nth percentile for the first item only
                            if (cacti_sizeof($special_hrules) == 1) {
                                db_execute_prepared("INSERT INTO graph_templates_item
									(graph_template_id, local_graph_id, graph_type_id, consolidation_function_id, text_format, value, hard_return, gprint_id, sequence)
									VALUES (?, ?, 1, 1, '', '', 'on', 2, ?)", array($graph_template_id, $local_graph_id, $next_item_sequence++));
                            }

                            db_execute_prepared(
                                "INSERT INTO graph_templates_item
								(graph_template_id, local_graph_id, task_item_id, graph_type_id, color_id, consolidation_function_id, text_format, value, hard_return, gprint_id, sequence)
								VALUES (?, ?, ?, ?, ?, 1, ?, ?, '', 2, ?)",
                                array(
                                    $graph_template_id,
                                    $local_graph_id,
                                    $item['task_item_id'],
                                    GRAPH_ITEM_TYPE_HRULE,
                                    $item['color_id'],
                                    $item['text_format'],
                                    $item['value'],
                                    $next_item_sequence++
                                )
                            );
                        }
                    }

                    break;
            }
        }
    }
}
