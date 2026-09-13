<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 +-------------------------------------------------------------------------+
*/

/** @compatibility-contract Synthetic plugin using the public registration API. */
function plugin_compatibility_test_version() {
    return ['name' => 'compatibility_test', 'longname' => 'Behavioral Compatibility Fixture',
        'version' => '1.0.0', 'author' => 'Kadupul', 'homepage' => 'https://example.invalid'];
}
function compatibility_test_record($name, $args) {
    file_put_contents('/artifacts/plugin.jsonl', json_encode(['callback' => $name, 'args' => $args]) . "\n", FILE_APPEND | LOCK_EX);
}
function plugin_compatibility_test_install() {
    compatibility_test_record('install', []);
    foreach (['compatibility_event', 'poller_top', 'poller_bottom'] as $hook) {
        api_plugin_register_hook('compatibility_test', $hook, 'compatibility_test_event', 'setup.php');
    }
    foreach (['compatibility_filter', 'config_settings', 'draw_navigation_text'] as $hook) {
        api_plugin_register_hook('compatibility_test', $hook, 'compatibility_test_filter', 'setup.php');
    }
}
function plugin_compatibility_test_check_config() { return true; }
function plugin_compatibility_test_uninstall() { compatibility_test_record('uninstall', []); }
function compatibility_test_event(...$args) { compatibility_test_record('event', $args); return 'ignored-event-return'; }
function compatibility_test_filter($value) {
    // Keep real navigation/settings hooks transparent; dedicated hook tests payloads.
    compatibility_test_record('filter', [$value]);
    return $value;
}
