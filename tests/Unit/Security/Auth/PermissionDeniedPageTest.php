<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * The Permission Denied page must close its script tag, or the browser reads
 * the page script and the markup after it as attribute text.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function permission_denied_run(array $realms): array
{
    return auth_entry_probe_run(array(
        'page' => 'probe.php',
        'session' => array('sess_user_id' => 7),
        'config' => array('auth_method' => 1),
        'users' => array(array('id' => 7, 'username' => 'viewer', 'realm' => 0, 'enabled' => 'on', 'locked' => '', 'password' => 'stored-hash')),
        'realms' => $realms,
        'realm_filenames' => array('probe.php' => 5),
    ));
}

test('the permission denied page emits a well formed nonce script tag', function () {
    $result = permission_denied_run(array());

    expect($result['page_continued'])->toBeFalse()
        ->and($result['events'])->toContain('error_page')
        ->and($result['output'])->toContain("<script type='text/javascript' nonce='probe'>")
        ->and(substr_count($result['output'], '<script'))->toBe(substr_count($result['output'], '</script>'));
});

test('a user holding the realm still reaches the page', function () {
    $result = permission_denied_run(array(array(7, 5)));

    expect($result['page_continued'])->toBeTrue()
        ->and($result['output'])->toBe('');
});
