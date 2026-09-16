<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});

$config = array('base_path' => getenv('SPIKE_TEST_ROOT'), 'poller_id' => 1, 'cacti_server_os' => 'unix');
function read_config_option($name, $default = '')
{
    $options = array('path_rrdtool' => getenv('SPIKE_TEST_RRDTOOL'), 'spikekill_backupdir' => getenv('SPIKE_TEST_BACKUP'));
    return $options[$name] ?? $default;
}
function read_user_setting($name, $default = '', $force = false)
{
    return $default;
}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function cacti_log(...$args) {}
function number_format_i18n($value, $decimals = 0)
{
    return number_format($value, $decimals);
}
function __($format, ...$args)
{
    return $args ? vsprintf($format, $args) : $format;
}
function __esc($format, ...$args)
{
    return htmlspecialchars(__($format, ...$args), ENT_QUOTES);
}
function get_execution_user()
{
    return get_current_user();
}
define('POLLER_VERBOSITY_DEBUG', 0);
require getenv('SPIKE_TEST_ROOT') . '/tests/Helpers/SpikekillPathFunctions.php';
