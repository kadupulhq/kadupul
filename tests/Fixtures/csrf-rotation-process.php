<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Full production CLI/functions/poller, with storage and device/network
// boundaries isolated. The optional MySQL profile uses real PDO sessions.
$root = getenv('CSRF_ROTATION_SOURCE');
$fixture = getenv('CSRF_ROTATION_FIXTURE');
$actor = getenv('CSRF_ROTATION_ACTOR');
require $root . '/include/vendor/autoload.php';
require $root . '/include/global_constants.php';
require $root . '/tests/Fixtures/csrf-rotation-sqlite.php';
$database_hostname = 'fixture';
$database_port = 0;
$database_default = 'fixture';
$config = array('base_path' => $fixture, 'include_path' => $root . '/include', 'is_web' => false, 'poller_id' => 1,
    'config_options_array' => array('poller_interval' => 300));
$database_sessions = array('fixture:0:fixture' => csrf_rotation_test_connection('primary'));
function csrf_rotation_test_connection(string $name): PDO
{
    $profile = getenv('CSRF_ROTATION_MYSQL');
    if ($profile !== false && $profile !== '') {
        $profile = json_decode($profile, true, 512, JSON_THROW_ON_ERROR);
        return new PDO($profile[$name], $profile['user'], $profile['password'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT));
    }

    $fixture = getenv('CSRF_ROTATION_FIXTURE');
    return new CsrfRotationSqlite($fixture . '/' . $name . '.sqlite', $fixture, $fixture . '/' . $name . '.lock');
}
function db_fetch_assoc($sql)
{
    return $GLOBALS['database_sessions']['fixture:0:fixture']->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_row_prepared($sql, $params, $log = true)
{
    if (str_contains($sql, 'FROM poller')) {
        return array('dbhost' => 'collector' . $params[0], 'dbuser' => 'fixture', 'dbpass' => '', 'dbdefault' => 'fixture', 'dbport' => 0,
            'dbretries' => 0, 'dbssl' => 0, 'dbsslkey' => '', 'dbsslcert' => '', 'dbsslca' => '', 'name' => 'fixture');
    }
    $query = $GLOBALS['database_sessions']['fixture:0:fixture']->prepare($sql);
    $query->execute($params);
    return $query->fetch(PDO::FETCH_ASSOC) ?: array();
}
function db_execute_prepared($sql, $params, $log = true, $connection = false)
{
    $connection = $connection ?: $GLOBALS['database_sessions']['fixture:0:fixture'];
    $query = $connection->prepare($sql);
    return $query !== false && $query->execute($params);
}
function db_connect_real($host, ...$ignored)
{
    $fixture = getenv('CSRF_ROTATION_FIXTURE');
    if (getenv('CSRF_ROTATION_ACTOR') === 'A' && $host === 'collector3' && getenv('CSRF_ROTATION_PAUSE') === '1') {
        touch($fixture . '/A-paused');
        $deadline = microtime(true) + 40;
        while (!is_file($fixture . '/resume-A')) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Fixture handoff timeout');
            }
            usleep(10000);
        }
    }
    $connection = csrf_rotation_test_connection($host);
    if ($host === 'collector3' && getenv('CSRF_ROTATION_DELAY') === '1') {
        if ($connection instanceof CsrfRotationSqlite) {
            $connection->sqliteCreateFunction('rotation_delay', static function () use ($fixture): int {
                if (getenv('CSRF_ROTATION_ACTOR') === 'A') {
                    touch($fixture . '/remote-statement-running');
                    usleep(35000000);
                }
                return 1;
            });
        } elseif (getenv('CSRF_ROTATION_ACTOR') === 'A') {
            $connection->exec('SET @csrf_rotation_review_delay = 35');
            file_put_contents($fixture . '/remote-connection-id', (string) $connection->query('SELECT CONNECTION_ID()')->fetchColumn());
            touch($fixture . '/remote-statement-starting');
        }
    }
    return $connection;
}
require $root . '/lib/functions.php';
require $root . '/include/csrf.php';
touch($fixture . '/' . $actor . '-started');
register_shutdown_function(static function (): void {
    $read = $GLOBALS['database_sessions']['fixture:0:fixture']->query('SELECT value FROM settings WHERE name = "csrf_secret"');
    if ($read !== false && is_string($read->fetchColumn())) {
        $GLOBALS['nativeChildCoverageMarkers'][] = 'rotation-state-readback';
    }
});
