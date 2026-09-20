<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/*
 * Tests for command injection hardening in graph_realtime.php.
 *
 * Source contracts complement the executable RealtimePollerExecutionTest.
 * The poller invocation now uses argv instead of shell escaping.
 */

$graphRealtimePath = __DIR__ . '/../../graph_realtime.php';

// --- graph_realtime.php: shell-free poller invocation ---

test('graph_realtime.php passes the PHP binary directly to argv execution', function () use ($graphRealtimePath) {
    $contents = file_get_contents($graphRealtimePath);

    expect($contents)->toContain("cacti_exec(read_config_option('path_php_binary'), array(");
});

test('graph_realtime.php passes the poller script as an argument', function () use ($graphRealtimePath) {
    $contents = file_get_contents($graphRealtimePath);

    expect($contents)->toContain("\$config['base_path'] . '/poller_realtime.php'");
    expect($contents)->toContain('poller_realtime.php');
});

test('graph_realtime.php requires a positive integer graph identifier', function () use ($graphRealtimePath) {
    $contents = file_get_contents($graphRealtimePath);

    expect($contents)->toContain('!is_int($local_graph_id) || $local_graph_id < 1');
});

test('graph_realtime.php has no shell execution sink', function () use ($graphRealtimePath) {
    $contents = file_get_contents($graphRealtimePath);

    expect($contents)->not->toContain('shell_exec(');
});
