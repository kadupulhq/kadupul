<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Translation, realm discovery and plugin registration are unrelated boundaries.
function __($message, ...$args)
{
    return $message;
}
function __x($context, $message, ...$args)
{
    return $message;
}
function get_auth_realms()
{
    return [];
}
function api_plugin_hook($hook) {}
$root = dirname(__DIR__, 2);
$config = ['cacti_version' => '1.2.34', 'is_web' => false, 'poller_id' => 1, 'connection' => 'local', 'base_path' => $root, 'url_path' => '/', 'cacti_server_os' => 'unix'];
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
require $root . '/include/global_arrays.php';
echo json_encode([-1, ...array_keys($item_rows)], JSON_THROW_ON_ERROR);
