<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * A login that upgrades an old password hash must write only the local row
 * that logged in. The same username can exist in the LDAP and domain realms,
 * and those rows used to receive a hash of the local password too. A refused
 * login must not rehash at all.
 */

require_once dirname(__DIR__, 3) . '/Helpers/LocalLoginProbe.php';

test('a rehash writes only the local row that logged in', function () {
    $scenario = local_login_policy_scenario('alice', 'weak', array('rehash' => true));
    $scenario['config'] = array();

    $result = local_login_policy_run($scenario);
    $writes = local_login_policy_writes($result, '/SET password = \?/');

    expect($writes)->toHaveCount(1)
        ->and($writes[0]['sql'])->toContain('WHERE id = ? AND realm = 0')
        ->and($writes[0]['params'])->toBe(array('rehash:weak', 42, 'hash:weak'));
});

test('a refused login does not rehash', function () {
    $scenario = local_login_policy_scenario('alice', 'weak', array('rehash' => true));
    $scenario['config'] = array();
    $scenario['users'][0]['enabled'] = '';

    $result = local_login_policy_run($scenario);

    expect($result['error'])->toBeTrue()
        ->and(local_login_policy_writes($result, '/SET password = \?/'))->toBe(array());
});
