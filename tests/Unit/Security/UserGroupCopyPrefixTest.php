<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace UserGroupCopyPrefixTest;

// The copy action names new groups from a request value without going through
// form_save(), so user_group_copy() has to apply the same name rule itself.

$source = file_get_contents(dirname(__DIR__, 3) . '/user_group_admin.php');

function prefix_copy_run(array $prefixes, string $scenario = 'wiring-prefixes'): array
{
    $root = dirname(__DIR__, 3);
    $directory = sys_get_temp_dir() . '/group-prefix-native-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'auto_prepend_file=', $root . '/tests/Fixtures/group-copy-native.php', $scenario, $directory, '', json_encode($prefixes, JSON_THROW_ON_ERROR)), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        expect(is_resource($process))->toBeTrue();
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error)->and($error)->toBe('');
        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        rmdir($directory);
    }
}

dataset('rejected prefixes', array(
    '<img src=x a="',
    '" onerror=alert(1)>',
    "O'Brien",
    'Ops;Admins',
    "Ops\nAdmins",
));

test('group copies refuse a prefix the group name rule rejects', function ($prefix) {
    $result = prefix_copy_run(array($prefix));
    expect($result['status'])->toBeFalse()->and($result['copies'])->toBe(array())
        ->and($result['transaction'])->toBeFalse();
})->with('rejected prefixes');

test('group copies keep working for prefixes the edit form accepts', function () {
    $result = prefix_copy_run(array('New Group', 'ops.team_1 @site-2', 'DOMAIN\\Ops', ''));
    expect($result['status'])->toBeTrue()->and($result['transaction'])->toBeFalse()
        ->and(array_column($result['copies'], 'name'))->toBe(array('New Group 1', 'ops.team_1 @site-2 2', 'DOMAIN\\Ops 3', ' 4'));

});

test('the copy action reports a rejected prefix instead of copying', function () use ($source) {
    expect($source)->toContain("raise_message('group_prefix'", "raise_message(2)");

});


test('group copies validate the final generated name at the storage boundary', function () {
    foreach (array(17 => true, 18 => true, 19 => false) as $length => $accepted) {
        $result = prefix_copy_run(array(str_repeat('A', $length)));
        expect($result['status'])->toBe($accepted)
            ->and($result['transaction'])->toBeFalse()
            ->and(count($result['copies']))->toBe($accepted ? 1 : 0);
        if ($accepted) {
            expect($result['copies'][0]['name'])->toBe(str_repeat('A', $length) . ' 1');
        }
    }
});

test('group copies validate the numeric suffix again for every copied group', function () {
    $result = prefix_copy_run(array_fill(0, 10, str_repeat('A', 18)));
    expect($result['status'])->toBeFalse()->and($result['transaction'])->toBeFalse()
        ->and(count($result['copies']))->toBe(9)
        ->and(array_column($result['copies'], 'name'))->toBe(array_map(
            static fn($suffix) => str_repeat('A', 18) . ' ' . $suffix,
            range(1, 9)
        ));
});


test('the native bulk copy action reports the generated name limit without persistence', function () {
    $result = prefix_copy_run(array(), 'wiring-length-action');
    expect($result['copies'])->toBe(array())->and($result['transaction'])->toBeFalse()
        ->and($result['messages'])->toBe(array('group_prefix'))
        ->and($result['message_texts'])->toBe(array('The copied Group Name must not exceed 20 characters, including the numeric suffix.'));
});
