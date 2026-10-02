<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/../Helpers/PluginCompatibilityNativeHarness.php';

test('production plugin compatibility and list status agree at all boundaries', function ($scenario, $expected) {
    $result = PluginCompatibilityNativeHarness::run($scenario, $this->getTestResultObject()->getCodeCoverage());
    $check = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
    expect($check['compat']['compat'])->toBe($expected)
        ->and($check['status'])->toBe($expected ? 0 : (!empty($scenario['missing_info']) ? -4 : -1))
        ->and($result['setup'])->toBeFalse()->and($result['writes'])->toBe(array());
})->with(array(
    array(array('metadata' => 'compat = 1.3.0'), true), array(array('metadata' => 'compat = 1.3.1'), false),
    array(array('metadata' => 'compat = " 1.2 "'), true), array(array('metadata' => 'compat = 1.2.0.1'), false),
    array(array('metadata' => 'compat = 1.2.x'), false), array(array('metadata' => 'compat = 1.3.0-dev'), false),
    array(array('metadata' => ''), false), array(array('metadata' => 'compat[] = 1.2'), false), array(array('missing_info' => true), false)
));

test('production plugin action links use the lowercase directory and explain failures', function ($metadata, $needle) {
    $result = PluginCompatibilityNativeHarness::run(array('mode' => 'render', 'metadata' => $metadata), $this->getTestResultObject()->getCodeCoverage());
    expect($result['stdout'])->toContain($needle);
})->with(array(array('compat = 1.3.0', 'piinstall'), array('compat = 1.3.1', 'Unable to Install Plugin: Requires: Kadupul &gt;= 1.3.1'), array("compat = 1.3.0\nrequires = absent:99", 'Absent Version 99')));

test('production plugin CLI reports each install outcome accurately', function ($scenario, $expectedStatus, $message, $setup) {
    $result = PluginCompatibilityNativeHarness::run(array_merge(array('mode' => 'cli'), $scenario), $this->getTestResultObject()->getCodeCoverage());
    expect($result['status'])->toBe($expectedStatus)->and($result['stdout'])->toContain($message)->and($result['setup'])->toBe($setup);
    if (!$setup) {
        expect($result['writes'])->toBe(array());
    }
})->with(array(
    array(array('metadata' => 'compat = 99.0.0'), 1, 'can not install', false),
    array(array('metadata' => ''), 1, 'can not install', false),
    array(array('plugin' => 'missing'), 1, 'missing plugin directory', false),
    array(array('persist_fail' => true), 1, 'installation failed', true),
    array(array(), 0, 'installed successfully', true)
));

test('production web plugin install rejects incompatibility before setup and accepts compatible metadata', function ($metadata, $compatible) {
    $result = PluginCompatibilityNativeHarness::run(array('mode' => 'web', 'metadata' => $metadata), $this->getTestResultObject()->getCodeCoverage());
    expect($result['headers'][0])->toContain('302')->and($result['setup'])->toBe($compatible)->and($result['install_defined'])->toBe($compatible);
    if (!$compatible) {
        expect($result['headers'])->toContain('Location: plugins.php?header=false')
            ->and($result['messages']['dependency_check'])->toContain('Requires: Kadupul >= 99.0.0')
            ->and($result['writes'])->toBe(array())
            ->and($result['installed'])->toBe(array());
    } else {
        expect($result['writes'])->not->toBeEmpty()
            ->and($result['installed'])->toBe(array(array('directory' => 'fixture', 'status' => 4)));
    }
})->with(array(array('compat = 99.0.0', false), array('compat = 1.3.0', true)));

test('production plugin hook lifecycle preserves configuration hooks and updates existing rows', function () {
    $native = PluginCompatibilityNativeHarness::run(array('mode' => 'api', 'operation' => 'hooks'), $this->getTestResultObject()->getCodeCoverage());
    $result = json_decode($native['stdout'], true, 512, JSON_THROW_ON_ERROR);
    expect($result['ordinary'][0]['status'])->toBe(0)
        ->and($result['updated'][0]['function'])->toBe('updated')->and($result['updated'][0]['status'])->toBe(1)
        ->and(array_column($result['disabled'], 'status'))->toBe(array(4, 1))
        ->and(array_column($result['enabled'], 'status'))->toBe(array(1, 1))
        ->and(array_column($result['disabled_all'], 'status'))->toBe(array(0, 0))
        ->and($result['explicit'][0]['function'])->toBe('explicit')->and($result['explicit'][0]['status'])->toBe(1)
        ->and($result['removed'])->toBe(array());
});

test('production realm registration grants admin and session users and consolidates duplicate ownership', function () {
    $native = PluginCompatibilityNativeHarness::run(array('mode' => 'api', 'operation' => 'realms'), $this->getTestResultObject()->getCodeCoverage());
    $result = json_decode($native['stdout'], true, 512, JSON_THROW_ON_ERROR);
    expect($result['created'][0]['file'])->toBe('a.php')
        ->and($result['grants'])->toBe(array(array('user_id' => 1, 'realm_id' => 101), array('user_id' => 2, 'realm_id' => 101)))
        ->and($result['updated'][0]['display'])->toBe('Updated')
        ->and(array_column($result['consolidated'], 'id'))->toBe(array(1, 3))
        ->and($result['consolidated'][1]['file'])->toBe('b.php,c.php')
        ->and($result['groups'])->toBe(array(array('group_id' => 4, 'realm_id' => 101)))
        ->and($result['consolidated_grants'][2]['realm_id'])->toBe(101)
        ->and($result['removed'])->toBe(array())->and($result['remaining_grants'])->toBe(array())->and($result['remaining_groups'])->toBe(array());
});

test('production plugin lifecycle reinstalls enables disables reorders and removes its own configuration', function () {
    $native = PluginCompatibilityNativeHarness::run(array('mode' => 'api', 'operation' => 'lifecycle'), $this->getTestResultObject()->getCodeCoverage());
    $result = json_decode($native['stdout'], true, 512, JSON_THROW_ON_ERROR);
    expect($result['is_enabled'])->toBeTrue()->and($result['is_enabled_cached'])->toBeTrue()->and($result['absent_enabled'])->toBeFalse()->and($result['installed'][1]['status'])->toBe(4)->and($result['enabled'][1]['status'])->toBe(1)
        ->and($result['disabled'][1]['status'])->toBe(4)->and($result['moved'][0]['directory'])->toBe('fixture')
        ->and(array_column($result['uninstalled'], 'directory'))->toBe(array('before'));
});

test('production orphan cleanup preserves internal hooks realms and grants', function () {
    $native = PluginCompatibilityNativeHarness::run(array('mode' => 'api', 'operation' => 'cleanup'), $this->getTestResultObject()->getCodeCoverage());
    $result = json_decode($native['stdout'], true, 512, JSON_THROW_ON_ERROR);
    expect(array_column($result['plugin_hooks'], 'name'))->toBe(array('internal'))
        ->and(array_column($result['plugin_db_changes'], 'plugin'))->toBe(array('internal'))
        ->and(array_column($result['plugin_realms'], 'plugin'))->toBe(array('internal'))
        ->and(array_column($result['user_auth_realm'], 'realm_id'))->toBe(array(102))
        ->and(array_column($result['user_auth_group_realm'], 'realm_id'))->toBe(array(102));
});


test('production plugin schema changes track ownership and remove only registered tables and columns', function () {
    $native = PluginCompatibilityNativeHarness::run(array('mode' => 'api', 'operation' => 'schema'), $this->getTestResultObject()->getCodeCoverage());
    $result = json_decode($native['stdout'], true, 512, JSON_THROW_ON_ERROR);
    expect($result['changes'])->toBe(array(array('plugin' => 'fixture', 'table' => 'owned', 'column' => '', 'method' => 'create'), array('plugin' => 'fixture', 'table' => 'existing', 'column' => 'added', 'method' => 'addcolumn')))
        ->and(array_column($result['columns'], 'name'))->toBe(array('id', 'added'))
        ->and($result['removed_changes'])->toBe(array())->and($result['remaining_tables'])->toBe(array())
        ->and(array_column($result['remaining_columns'], 'name'))->toBe(array('id'));
});
