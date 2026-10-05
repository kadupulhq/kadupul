<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Return graph-input data-source choices within the current actor's device policy. */
function graph_item_choices(): array
{
    if (!is_realm_allowed(5)) {
        http_response_code(403);

        return [];
    }

    $rrd = get_nfilter_request_var('rrd_id');
    $host = get_nfilter_request_var('host_id');
    $term = get_nfilter_request_var('term');
    $rrdId = auth_resource_id($rrd === '' || $rrd === null ? 0 : $rrd);
    $hostId = auth_resource_id($host === '' || $host === null ? 0 : $host);
    if ($rrdId === null || $hostId === null || !is_scalar($term)) {
        http_response_code(400);

        return [];
    }

    if ($hostId > 0 && !is_device_allowed($hostId)) {
        http_response_code(403);

        return [];
    }

    // Reuse the management-device policy as SQL so even a large device
    // inventory is filtered in the database, never hydrated in this picker.
    // Non-device sources remain valid, as in api_data_source_is_allowed().
    $deviceSql = get_allowed_management_device_ids_sql();
    $scope = "(data_local.host_id=0 OR data_local.host_id IN ($deviceSql))";
    $filter = $hostId > 0 ? ' AND data_local.host_id=' . $hostId : '';
    $nameExpression = "CONCAT_WS('', CASE WHEN host.description IS NULL THEN " . db_qstr(__esc('No Device - ')) . " ELSE '' END, data_template_data.name_cache,' (',data_template_rrd.data_source_name,')')";
    $parameters = [$rrdId];
    if ((string) $term !== '') {
        $filter .= " AND $nameExpression LIKE ?";
        $parameters[] = '%' . (string) $term . '%';
    }

    // The shipped autocomplete setting offers at most 5,000 rows. Retain
    // configured smaller limits and fail closed on an invalid setting.
    $limit = auth_resource_id(read_config_option('autocomplete_rows'));
    if ($limit === null || $limit < 1 || $limit > 5000) {
        http_response_code(500);

        return [];
    }

    $items = db_fetch_assoc_prepared(
        "SELECT * FROM (
            SELECT data_template_rrd.id AS id,
                $nameExpression AS name
            FROM data_template_data
            INNER JOIN data_local ON data_template_data.local_data_id=data_local.id
            INNER JOIN data_template_rrd ON data_template_rrd.local_data_id=data_local.id
            LEFT JOIN host ON data_local.host_id=host.id
            WHERE data_template_rrd.id=? AND $scope
        ) AS a
        UNION
        SELECT * FROM (
            SELECT data_template_rrd.id AS id,
                $nameExpression AS name
            FROM data_template_data
            INNER JOIN data_local ON data_template_data.local_data_id=data_local.id
            INNER JOIN data_template_rrd ON data_template_rrd.local_data_id=data_local.id
            LEFT JOIN host ON data_local.host_id=host.id
            WHERE $scope $filter
            ORDER BY name
        ) AS b
        LIMIT " . $limit,
        $parameters
    );
    foreach ($items as &$item) {
        $item['label'] = $item['name'];
    }
    unset($item);

    return $items;
}
