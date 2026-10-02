<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/../Helpers/PluginManageNativeHarness.php';

test('native plugin realm grants execute against exact production database tables', function () {
    $result = PluginManageNativeHarness::run(array('mysql' => true, 'plugins' => array('fixture', 'fixture')));
    expect($result['status'])->toBe(0)
        ->and($result['calls'])->toHaveCount(4)
        ->and($result['grants'])->toBe(array(array('user_id' => 7, 'realm_id' => 112), array('user_id' => 7, 'realm_id' => 113), array('user_id' => 8, 'realm_id' => 114)));
    $missing = PluginManageNativeHarness::run(array('mysql' => true, 'admin' => '99'));
    expect($missing['status'])->toBe(1)
        ->and($missing['stdout'])->toContain('administrator was not found')
        ->and($missing['calls'])->toBe(array())
        ->and($missing['grants'])->toBe(array(array('user_id' => 7, 'realm_id' => 112), array('user_id' => 8, 'realm_id' => 114)));
});
