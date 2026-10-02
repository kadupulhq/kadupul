<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/../Helpers/PluginManageNativeHarness.php';

test('native plugin CLI grants only configured admin realms and remains idempotent', function ($scenario) {
    $result = PluginManageNativeHarness::run($scenario, $this->getTestResultObject()->getCodeCoverage());
    expect($result['status'])->toBe(0)
        ->and($result['stdout'])->toContain("Enabled Plugin 'fixture' permissions")
        ->and($result['grants'])->toBe(array(array('user_id' => 7, 'realm_id' => 112), array('user_id' => 7, 'realm_id' => 113), array('user_id' => 8, 'realm_id' => 114)));
    if (!empty($scenario['fresh'])) {
        expect($result['calls'])->toContain(array('install', 'fixture'));
    }
    if (count($scenario['plugins'] ?? array('fixture')) === 2) {
        expect($result['calls'])->toHaveCount(4);
    }
})->with(array(array(array()), array(array('fresh' => true)), array(array('plugins' => array('fixture', 'fixture')))));

test('native plugin CLI rejects invalid and missing administrators without writes', function ($admin, $message) {
    $result = PluginManageNativeHarness::run(array('admin' => $admin), $this->getTestResultObject()->getCodeCoverage());
    expect($result['status'])->toBe(1)
        ->and($result['stdout'])->toContain($message)
        ->and($result['stdout'])->not->toContain('Enabled Plugin')
        ->and($result['calls'])->toBe(array())
        ->and($result['grants'])->toBe(array(array('user_id' => 7, 'realm_id' => 112), array('user_id' => 8, 'realm_id' => 114)));
})->with(array(array('', 'administrator is invalid'), array('0', 'administrator is invalid'), array('-1', 'administrator is invalid'), array('abc', 'administrator is invalid'), array('99', 'administrator was not found')));

test('native plugin CLI grants to the smallest valid administrator', function () {
    $result = PluginManageNativeHarness::run(array('admin' => '1'), $this->getTestResultObject()->getCodeCoverage());
    expect($result['status'])->toBe(0)
        ->and($result['grants'])->toBe(array(array('user_id' => 1, 'realm_id' => 112), array('user_id' => 1, 'realm_id' => 113), array('user_id' => 7, 'realm_id' => 112), array('user_id' => 8, 'realm_id' => 114)));
});

test('native plugin CLI reports failed grants and failed verification without success notes', function ($flag, $message) {
    $result = PluginManageNativeHarness::run(array($flag => true), $this->getTestResultObject()->getCodeCoverage());
    expect($result['status'])->toBe(1)
        ->and($result['stdout'])->toContain($message)
        ->and($result['stdout'])->not->toContain('Enabled Plugin')
        ->and($result['calls'])->toHaveCount(2)
        ->and($result['grants'])->toBe(array(array('user_id' => 7, 'realm_id' => 112), array('user_id' => 8, 'realm_id' => 114)));
})->with(array(array('grant_fail', 'Could not grant Plugin'), array('verify_fail', 'Could not verify Plugin')));

test('native plugin CLI processes later plugins after a failure', function () {
    $result = PluginManageNativeHarness::run(array('plugins' => array('fixture', 'second'), 'first_fail' => true), $this->getTestResultObject()->getCodeCoverage());
    expect($result['status'])->toBe(1)
        ->and($result['stdout'])->toContain("Enabled Plugin 'second' permissions")
        ->and($result['stdout'])->not->toContain("Enabled Plugin 'fixture' permissions")
        ->and($result['grants'])->toBe(array(array('user_id' => 7, 'realm_id' => 112), array('user_id' => 7, 'realm_id' => 115), array('user_id' => 8, 'realm_id' => 114)));
});

test('native plugin CLI reports missing directory without grant attempts', function () {
    $result = PluginManageNativeHarness::run(array('plugins' => array('missing')), $this->getTestResultObject()->getCodeCoverage());
    expect($result['status'])->toBe(1)
        ->and($result['stdout'])->toContain('missing plugin directory')
        ->and($result['calls'])->toBe(array());
});
