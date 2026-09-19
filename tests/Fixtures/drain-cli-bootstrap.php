<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
$config = array('base_path' => getenv('DRAIN_FIXTURE'));
function cacti_sizeof($v)
{
    return count($v);
}
function rrd_init(...$args)
{
    return getenv('DRAIN_MODE') !== 'init';
}
function rrd_close($pipe)
{
    file_put_contents(getenv('DRAIN_FIXTURE') . '/closed', 'yes');
}
function db_fetch_cell($sql)
{
    static $calls = 0;
    $calls++;
    $mode = getenv('DRAIN_MODE');
    if ($mode === 'count' || ($mode === 'recount' && $calls === 2)) {
        return false;
    }
    if ($mode === 'stalled') {
        return 1;
    }
    return $calls === 1 && $mode !== 'empty' ? 1 : 0;
}
function process_poller_output(...$args)
{
    return getenv('DRAIN_MODE') === 'write' ? false : 1;
}
