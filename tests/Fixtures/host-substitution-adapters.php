<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

function cacti_sizeof($value)
{
    return count($value);
}
function get_uptime($host)
{
    $GLOBALS['host_uptime_calls'][] = $host;
    return 'observed uptime';
}
function db_fetch_row_prepared($sql, $parameters)
{
    $GLOBALS['host_legacy_reads'][] = $parameters;
    $statement = $GLOBALS['host_database']->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
}
function api_plugin_hook_function($name, $payload)
{
    $GLOBALS['host_hook_calls'][] = [$name, $payload];
    if (isset($GLOBALS['host_hook_result'])) $payload['string'] = $GLOBALS['host_hook_result'];
    return $payload;
}
