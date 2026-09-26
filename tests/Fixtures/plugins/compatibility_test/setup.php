<?php
/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
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

function compatibility_create_lock($value) {
    if (str_starts_with($value['description'] ?? '', 'locked-create-fixture')) {
        file_put_contents('/artifacts/create-lock-ready', 'ready');
        $until = microtime(true) + 30;
        while (!is_file('/artifacts/create-lock-release')) {
            if (microtime(true) > $until) { throw new RuntimeException('Fixture release missing'); }
            usleep(10000);
            clearstatcache(true, '/artifacts/create-lock-release');
        }
    }
    return $value;
}

function compatibility_create_guard($value) {
    $prefix = 'create-hook-change-';
    if (str_starts_with($value['description'] ?? '', $prefix)) {
        $key = substr($value['description'], strlen($prefix));
        $value[$key] = $key === 'id' ? db_fetch_cell('SELECT MIN(id) FROM host') : 999999;
    }
    if (($value['description'] ?? '') === 'create-debug-fixture') {
        global $config;
        $config['DEBUG_SQL_CMD'] = true;
        $config['DEBUG_SQL_FLOW'] = true;
        cacti_log('Fixture credential: ' . $value['snmp_community'], false, 'DBCALL');
        db_echo_sql('Fixture credential: ' . $value['snmp_community']);
        // Exercise the actual database-error path, including its returned message.
        db_execute_prepared("SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = ?", [$value['snmp_community']]);
    }
    return $value;
}

function compatibility_template_collector_lock($value) {
    $collector = (int) db_fetch_cell_prepared('SELECT poller_id FROM host WHERE id = ?', [$value['device_id']]);
    $database = new \Kadupul\Platform\Infrastructure\Legacy\InstallationDatabase(new \Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration(getcwd()));
    $rival = $database->get();
    $rival->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $rival->beginTransaction();
    try {
        $query = $rival->prepare("UPDATE poller SET disabled='on' WHERE id = ?");
        $query->execute([$collector]);
        throw new RuntimeException('Collector configuration was not locked');
    } catch (PDOException $error) {
        if (($error->errorInfo[1] ?? null) !== 1205) {
            throw $error;
        }
        compatibility_test_record('template_collector_lock', [$collector]);
    } finally {
        $rival->rollBack();
    }
    return $value;
}

function compatibility_statistics_action($value) {
    compatibility_test_record('statistics_action', [$value]);
    return $value;
}
