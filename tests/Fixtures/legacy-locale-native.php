<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Runs the real include/global_languages.php the way include/global.php does:
// before the Composer autoloader and before global_settings.php. Only the
// settings, user-setting and plugin database reads are doubles; the request
// helpers, locale table, catalogs and php-gettext handler are the shipped ones.

$root = $argv[1];
$fixture = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
$base = $argv[3];

function read_config_option($name, $force = false)
{
    // An absent row falls back to read_default_config_option(), which finds
    // no $settings yet at this point of the bootstrap and returns null.
    return $GLOBALS['locale_fixture']['settings'][$name] ?? null;
}

function read_default_config_option($name)
{
    return $GLOBALS['locale_fixture']['defaults'][$name] ?? null;
}

function set_user_setting($name, $value, $user = -1)
{
    $GLOBALS['locale_user_writes'][] = [$name, $value];
}

function db_table_exists($table)
{
    return $table === 'settings_user';
}

function db_fetch_row_prepared($sql, $params = [])
{
    [$name, $user] = $params;
    $value = $GLOBALS['locale_fixture']['saved'][$user][$name] ?? null;

    return $value === null ? [] : ['value' => $value];
}

function db_fetch_cell_prepared($sql, $params = [])
{
    return $GLOBALS['locale_fixture']['guest'] ?? '';
}

function get_guest_account()
{
    return $GLOBALS['locale_fixture']['guest'] ?? 0;
}

function db_fetch_assoc($sql)
{
    return array_map(static fn(string $directory): array => ['directory' => $directory], $GLOBALS['locale_fixture']['plugins'] ?? []);
}

function cacti_sizeof($array)
{
    return ($array === false || !is_array($array)) ? 0 : sizeof($array);
}

$GLOBALS['locale_fixture'] = $fixture;
$GLOBALS['locale_user_writes'] = [];
require $root . '/lib/html_utility.php';
require $root . '/include/global_constants.php';

$_SESSION = $fixture['session'] ?? [];
$_REQUEST = $_GET = $fixture['request'] ?? [];
$_POST = [];
if (array_key_exists('accept', $fixture)) {
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $fixture['accept'];
}
$config = [
    'base_path' => $base,
    'include_path' => $root . '/include',
    'is_web' => true,
];
if (array_key_exists('forced', $fixture)) {
    $config['i18n_force_language'] = $fixture['forced'];
}

require $root . '/include/global_languages.php';

$result = [
    'CACTI_LOCALE' => CACTI_LOCALE,
    'CACTI_COUNTRY' => CACTI_COUNTRY,
    'CACTI_LANGUAGE' => CACTI_LANGUAGE,
    'cacti_locale' => $cacti_locale,
    'cacti_country' => $cacti_country,
    'session_language' => array_key_exists('sess_user_language', $_SESSION) ? $_SESSION['sess_user_language'] : null,
    'session_dates_kept' => isset($_SESSION['sess_current_date1'], $_SESSION['sess_current_date2']),
    'request_language' => array_key_exists('language', $_REQUEST) ? $_REQUEST['language'] : null,
    'user_writes' => $GLOBALS['locale_user_writes'],
    'domains' => array_keys($cacti_textdomains),
    'text' => __('Devices'),
];
if (isset($fixture['apply'])) {
    $result['apply'] = apply_locale($fixture['apply']);
    $result['apply_globals'] = [$cacti_locale, $cacti_country];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
