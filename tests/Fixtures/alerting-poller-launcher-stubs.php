<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
// The legacy helpers lib/alerting.php calls, for a test process of its own:
// settings come from $GLOBALS['alerting_launcher_settings'], and each
// exec_background() and cacti_log() call is recorded in order.

$GLOBALS['alerting_launcher_settings'] = [];
$GLOBALS['alerting_launcher_calls'] = [];

function read_config_option($name, $force = false)
{
    return $GLOBALS['alerting_launcher_settings'][$name] ?? '';
}

function exec_background($filename, $args = '', $redirect_args = '')
{
    $GLOBALS['alerting_launcher_calls'][] = ['exec', $filename, $args, $redirect_args];
}

function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '')
{
    $GLOBALS['alerting_launcher_calls'][] = ['log', $string, $environ];
}
