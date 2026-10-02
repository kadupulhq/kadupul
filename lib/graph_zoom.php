<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Resolve the selected stored RRA for graph zooming.
 *
 * @param array    $rras          RRAs associated with the graph.
 * @param mixed    $rra_id        Requested RRA identifier or the `all` value.
 * @param callable $fetch_rra     Callback that loads an RRA row by identifier.
 * @param callable $on_missing    Callback that reports and terminates a missing-data request.
 *
 * @return array The selected RRA with its total timespan.
 */
function graph_zoom_resolve_rra(array $rras, $rra_id, callable $fetch_rra, callable $on_missing): array
{
    if ($rras === []) {
        $on_missing();

        return [];
    }

    $selected_rra_id = (int) $rra_id > 0 ? (int) $rra_id : $rras[0]['id'];
    $rra             = $fetch_rra($selected_rra_id);

    if (!is_array($rra) || $rra === []) {
        $on_missing();

        return [];
    }

    $rra['timespan'] = $rra['steps'] * $rra['step'] * $rra['rows'];

    return $rra;
}
