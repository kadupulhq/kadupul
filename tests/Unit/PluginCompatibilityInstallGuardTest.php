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
