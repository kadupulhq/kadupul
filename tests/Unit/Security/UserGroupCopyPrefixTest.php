<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace UserGroupCopyPrefixTest;

// The copy action names new groups from a request value without going through
// form_save(), so user_group_copy() has to apply the same name rule itself.

$source = file_get_contents(dirname(__DIR__, 3) . '/user_group_admin.php');

if (!preg_match('/^function user_group_copy\\(.*?^\\}/ms', $source, $match)) {
    throw new \RuntimeException('Missing user_group_copy()');
}

eval('namespace UserGroupCopyPrefixTest; ' . $match[0]);

$GLOBALS['user_group_copy_writes'] = array();

function db_qstr($value)
{
    return "'" . addslashes((string) $value) . "'";
}

function db_execute_prepared($sql, $params = array())
{
    $GLOBALS['user_group_copy_writes'][] = $sql;

    return true;
}

// No new id, so the copy stops after the group row.
function db_fetch_insert_id()
{
    return 0;
}

function copied_names(): array
{
    $names = array();

    foreach ($GLOBALS['user_group_copy_writes'] as $sql) {
        if (preg_match("/SELECT '(.*?)', description/", $sql, $match)) {
            $names[] = stripslashes($match[1]);
        }
    }

    return $names;
}

dataset('rejected prefixes', array(
    '<img src=x a="',
    '" onerror=alert(1)>',
    "O'Brien",
    'Ops;Admins',
    "Ops\nAdmins",
));

test('group copies refuse a prefix the group name rule rejects', function ($prefix) {
    $GLOBALS['user_group_copy_writes'] = array();

    expect(user_group_copy(4, $prefix))->toBeFalse();
    expect($GLOBALS['user_group_copy_writes'])->toBe(array());
})->with('rejected prefixes');

test('group copies keep working for prefixes the edit form accepts', function () {
    $GLOBALS['user_group_copy_writes'] = array();

    expect(user_group_copy(4, 'New Group'))->toBeTrue();
    expect(user_group_copy(4, 'ops.team_1 @site-2'))->toBeTrue();
    expect(user_group_copy(4, 'DOMAIN\\Ops'))->toBeTrue();
    expect(user_group_copy(4, ''))->toBeTrue();

    expect(copied_names())->toBe(array('New Group 1', 'ops.team_1 @site-2 2', 'DOMAIN\\Ops 3', ' 4'));
});

test('the copy action reports a rejected prefix instead of copying', function () use ($source) {
    expect($source)->toMatch("/if \\(!user_group_copy\\(\\\$selected_items\\[\\\$i\\], get_nfilter_request_var\\('group_prefix'\\)\\)\\) \\{\\s*raise_message\\('group_prefix', __\\(.*?\\), MESSAGE_LEVEL_ERROR\\);\\s*break;/s");
});
