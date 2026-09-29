<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

$automationSource = file_get_contents(dirname(__DIR__, 2) . '/poller_automation.php');

test('automation discovery email escapes remote device text', function () use ($automationSource) {
    expect($automationSource)->toContain("html_escape(\$device['hostname'])");
    expect($automationSource)->toContain("html_escape(\$device['ip'])");
    expect($automationSource)->toContain("html_escape(\$device['sysName'])");
});

test('automation discovery email escapes network details', function () use ($automationSource) {
    expect($automationSource)->toContain("html_escape(\$network['name'])");
    expect($automationSource)->toContain("html_escape(\$network['subnet_range'])");
    expect($automationSource)->toContain("html_escape(\$network['last_started'])");
});
