<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
$config = array('base_path' => $root, 'cacti_server_os' => 'unix', 'is_web' => false, 'poller_id' => 1, 'connection' => 'online', 'url_path' => '/', 'config_options_array' => array('auth_method' => 1));
$_SESSION = array();
$no_http_header_files = array();

function __($text, ...$arguments)
{
    return $arguments === array() ? $text : vsprintf($text, $arguments);
}

function __x($context, $text, ...$arguments)
{
    return __($text, ...$arguments);
}

function db_table_exists($table, $log = true, $connection = false)
{
    return false;
}

function get_installed_locales()
{
    return array('en-US' => 'English');
}

function get_new_user_default_language()
{
    return 'en-US';
}

require $root . '/include/vendor/autoload.php';
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
require $root . '/lib/auth.php';
require $root . '/lib/plugins.php';
require $root . '/include/global_arrays.php';
require $root . '/include/global_settings.php';

fwrite(STDOUT, json_encode(array('tabs' => array_map('array_keys', $settings), 'neighbor' => $settings['poller']['disable_cache_replication']), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
