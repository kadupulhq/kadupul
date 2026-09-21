<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/*
 * Source contracts complement HostReindexExecutionTest's runtime coverage.
 */

$src = file_get_contents(__DIR__ . '/../../host.php');

test('host.php requires validated POST intent for reindexing', function () use ($src) {
    expect($src)->toContain("cacti_require_post_actions(array('actions', 'reindex'));");
});

test('host.php no longer executes a shell command', function () use ($src) {
    expect($src)->not->toContain('shell_exec(');
});

test('host.php passes validated IDs as argv without shell escaping', function () use ($src) {
    expect($src)->toContain('!is_int($host_id) || $host_id <= 0');
    expect($src)->toContain("cacti_exec(read_config_option('path_php_binary'), array(");
    expect($src)->toContain("'--qid=all', '--id=' . \$host_id");
    expect($src)->not->toContain('cacti_escapeshellarg((string) $host_id)');
});
