<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * The Permission Denied page must close its script tag, or the browser reads
 * the page script and the markup after it as attribute text.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function permission_denied_run(array $realms, int $realm = 5): array
{
    return auth_entry_probe_run(array(
        'page' => 'probe.php',
        'session' => array('sess_user_id' => 7),
        'config' => array('auth_method' => 1),
        'users' => array(array('id' => 7, 'username' => 'viewer', 'realm' => 0, 'enabled' => 'on', 'locked' => '', 'password' => 'stored-hash')),
        'realms' => $realms,
        'realm_filenames' => array('probe.php' => $realm),
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

test('denied pages expose a document language and a level-one heading', function (int $realm, string $title) {
    $result = permission_denied_run(array(), $realm);
    expect($result['stderr'])->toBe('')->and($result['page_continued'])->toBeFalse();
    $document = new DOMDocument();
    expect($document->loadHTML($result['output'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING))->toBeTrue();
    $xpath = new DOMXPath($document);
    expect($document->documentElement->getAttribute('lang'))->toBe('en-US')
        ->and($xpath->query('//h1[@class="loginHeading"]')->length)->toBe(1)
        ->and($xpath->evaluate('string(//h1)'))->toBe($title)
        ->and($xpath->query('//legend')->length)->toBe(0);
})->with(array(array(5, 'Permission Denied'), array(26, 'Installation In Progress')));
