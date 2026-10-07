<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/*
 * Source contracts complement HostReindexExecutionTest's runtime coverage.
 */

$src = file_get_contents(__DIR__ . '/../../host.php');

test('host.php delegates all device requests to the Symfony kernel', function () use ($src) {
    expect($src)->toContain('/inventory/devices/legacy')->toContain('$kernel->handle(');
});
test('host.php contains no shell execution or legacy reindex dispatch', function () use ($src) {
    expect($src)->not->toContain('shell_exec(')->not->toContain('cacti_exec(')->not->toContain('poller_reindex_hosts.php');
});
