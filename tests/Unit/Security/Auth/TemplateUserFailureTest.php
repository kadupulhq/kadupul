<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * When the template user is missing, a Web Basic login must get the
 * configured Basic failure page, as every other Web Basic failure does.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function template_failure_run(int $auth_method): array
{
    return auth_entry_probe_run(array(
        'config' => array('auth_method' => $auth_method, 'user_template' => 99, 'basic_auth_fail_message' => 'Ask the help desk for access.'),
        'users' => array(),
        'call' => array('type' => 'auth_login_create_user_from_template', 'args' => array('newuser', 2)),
    ));
}

test('a Web Basic login with a missing template user gets the Basic failure page', function () {
    $result = template_failure_run(2);

    expect($result['stderr'])->toBe('')
        ->and($result['error'])->toBeTrue()
        ->and($result['output'])->toContain('Ask the help desk for access.')
        ->and($result['return'])->toBeNull();
});

test('other methods report the missing template user to the login page', function () {
    $result = template_failure_run(1);

    expect($result['stderr'])->toBe('')
        ->and($result['error'])->toBeTrue()
        ->and($result['error_msg'])->toBe('Access Denied!  Template user id 99 does not exist.  Please contact your Administrator.')
        ->and($result['output'])->toBe('')
        ->and($result['return'])->toBe(array());
});
