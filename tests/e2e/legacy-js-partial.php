<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Prints what a plugin page returns to a header=false request when it calls
 * html_require_legacy_js() in on-demand mode. The production functions are
 * extracted from lib/, so the tags are the ones a page would send.
 * Usage: php legacy-js-partial.php <library> <nonce>
 */

require_once __DIR__ . '/../Helpers/PhpSource.php';

$root = dirname(__DIR__, 2);
[, $library, $nonce] = $argv;

define('KADUPUL_LEGACY_JS_ON_DEMAND', true);
define('POLLER_VERBOSITY_DEBUG', 5);

$GLOBALS['config'] = ['base_path' => $root, 'url_path' => '/', 'is_web' => false];
$GLOBALS['nonce'] = $nonce;
chdir($root);

class CactiSecureHeaders
{
    public static function getNonceAttribute()
    {
        return 'nonce="' . $GLOBALS['nonce'] . '"';
    }
}

function db_table_exists($table)
{
    return false;
}

function cacti_log() {}

function get_selected_theme()
{
    return 'modern';
}

function isset_request_var($name)
{
    return $name === 'header';
}

function get_nfilter_request_var($name)
{
    return 'false';
}

$functions = (string) file_get_contents($root . '/lib/functions.php');
foreach (['get_md5_hash', 'get_include_relpath', 'get_compiled_asset_path', 'get_md5_include_js', 'get_md5_include_css'] as $name) {
    eval(test_php_function_source($functions, $name));
}

$html = (string) file_get_contents($root . '/lib/html.php');
foreach (['html_page_chrome_requested', 'html_legacy_js_registry', 'html_legacy_js_global', 'html_legacy_js_state', 'html_legacy_js_tags', 'html_require_legacy_js'] as $name) {
    eval(test_php_function_source($html, $name));
}

print "<div id=\"plugin-page\">partial</div>\n";
html_require_legacy_js($library);
