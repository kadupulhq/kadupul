<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Edit Profile stores a user's graph settings. update_data wrote any value
 * under any known setting name, and skipped the graph_settings permission the
 * form save checks. The form save checked only numeric defaults. Stored
 * values reach script blocks, HTML attributes and font paths, so both paths
 * must accept only what the setting's form field could have sent.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AdminActionProbe.php';

function profile_setting_definitions(): array
{
    return array(
        'general' => array(
            'page_refresh' => array('method' => 'drop_array', 'default' => '300', 'array' => array('15' => '15s', '60' => '1m', '300' => '5m')),
            'hide_disabled' => array('method' => 'checkbox', 'default' => 'on'),
        ),
        'timespan' => array(
            'day_shift_start' => array('method' => 'textbox', 'default' => '07:00', 'max_length' => '5'),
        ),
        'tree' => array(
            'default_tree_id' => array('method' => 'drop_sql', 'sql' => 'SELECT id,name FROM graph_tree ORDER BY name', 'default' => '0'),
            'thumbnail_section' => array('method' => 'checkbox_group', 'items' => array('thumbnail_section_tree_2' => array('default' => ''))),
            'min_tree_width' => array('method' => 'textbox', 'default' => '170', 'max_length' => '5'),
        ),
        'fonts' => array(
            'title_font' => array('method' => 'font', 'max_length' => '100'),
        ),
    );
}

function profile_setting_run(string $function, array $request, bool $graph_settings = true, string $page = 'auth_profile.php', array $pluginFields = array()): array
{
    $functions = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');
    $stubs = test_php_function_source($functions, 'user_setting_value_allowed') . "\n"
        . test_php_function_source($functions, 'save_user_settings') . "\n"
        . 'function is_view_allowed($realm) { return ' . var_export($graph_settings, true) . ' && $realm === "graph_settings"; }' . "\n"
        . 'function set_user_setting($name, $value, $user = -1) { $GLOBALS["executed"][] = array("sql" => "set_user_setting", "params" => array($name, $value, $user)); }';

    return admin_action_probe_run(array(
        'page' => $page,
        'functions' => array($function),
        'request' => $request,
        'session' => array('sess_user_id' => 5),
        'globals' => array('settings_user' => profile_setting_definitions() + array('plugin' => $pluginFields)),
        'stubs' => $stubs,
        'answers' => array(array('assoc', '/FROM graph_tree/', array(array('id' => '4', 'name' => 'Main')))),
        'call' => $function === 'form_save' ? 'form_save()' : 'api_auth_update_user_setting(get_nfilter_request_var("name"), get_nfilter_request_var("value"))',
    ));
}

function profile_setting_update(string $name, string $value, bool $graph_settings = true): array
{
    $result = profile_setting_run('api_auth_update_user_setting', array('name' => $name, 'value' => $value), $graph_settings);

    return admin_action_probe_writes($result, '/^REPLACE INTO settings_user/');
}

test('update_data stores a value the field allows', function (string $name, string $value) {
    $writes = profile_setting_update($name, $value);

    expect($writes)->toHaveCount(1)
        ->and($writes[0]['params'])->toBe(array($name, $value, 5));
})->with(array(
    'listed choice' => array('page_refresh', '60'),
    'checkbox' => array('hide_disabled', ''),
    'time' => array('day_shift_start', '08:30'),
    'tree that exists' => array('default_tree_id', '4'),
    'number' => array('min_tree_width', '250'),
    'font path' => array('title_font', '/usr/share/fonts/DejaVuSans.ttf'),
));

test('update_data refuses a value the field does not allow', function (string $name, string $value) {
    expect(profile_setting_update($name, $value))->toBe(array());
})->with(array(
    'unlisted choice' => array('page_refresh', '1'),
    'checkbox text' => array('hide_disabled', 'yes'),
    'script in a number' => array('min_tree_width', '1;alert(1)//'),
    'markup in text' => array('title_font', '"><svg onload=alert(1)>'),
    'too long' => array('day_shift_start', '07:00:00'),
    'missing tree' => array('default_tree_id', '99'),
));

test('update_data needs the graph settings permission for graph settings', function () {
    expect(profile_setting_update('page_refresh', '60', false))->toBe(array());
});

test('update_data still saves the name and email without the graph settings permission', function () {
    $result = profile_setting_run('api_auth_update_user_setting', array('name' => 'full_name', 'value' => 'Alice'), false);

    expect(admin_action_probe_writes($result, '/^UPDATE user_auth SET full_name = \?/'))->toHaveCount(1);
});

test('the form save stores allowed values and flags the rest', function () {
    $result = profile_setting_run('form_save', array(
        'tab' => 'general',
        'page_refresh' => '60',
        'day_shift_start' => '<b>',
        'min_tree_width' => '300',
        'title_font' => "x'onmouseover=alert(1)",
        'default_tree_id' => '4',
    ));

    $stored = array_column(array_column(admin_action_probe_writes($result, '/^set_user_setting$/'), 'params'), 1, 0);

    expect($stored['page_refresh'] ?? null)->toBe('60')
        ->and($stored['min_tree_width'] ?? null)->toBe('300')
        ->and($stored['default_tree_id'] ?? null)->toBe('4')
        ->and($stored)->not->toHaveKey('day_shift_start')
        ->and($stored)->not->toHaveKey('title_font')
        ->and($result['session']['sess_error_fields'] ?? array())->toBe(array('day_shift_start' => 'day_shift_start', 'title_font' => 'title_font'))
        ->and($result['messages'])->toContain(35)
        ->and($result['messages'])->not->toContain(1);
});

test('the form save rejects malformed numeric defaults without replacing the submitted value', function () {
    $result = profile_setting_run('form_save', array('tab' => 'general', 'min_tree_width' => 'invalid-number'));
    $stored = array_column(array_column(admin_action_probe_writes($result, '/^set_user_setting$/'), 'params'), 1, 0);
    expect($stored)->not->toHaveKey('min_tree_width')
        ->and($result['session']['sess_error_fields'])->toHaveKey('min_tree_width')
        ->and($result['session']['sess_field_values']['min_tree_width'])->toBe('invalid-number')
        ->and($result['messages'])->toContain(35)->not->toContain(1);
});


test('administrator graph settings reports failure without a success message for rejected input', function () {
    $result = profile_setting_run('form_save', array('id' => 42, 'save_component_graph_settings' => '1', 'min_tree_width' => 'invalid-number'), true, 'user_admin.php');
    expect($result['session']['sess_error_fields'])->toHaveKey('min_tree_width')
        ->and($result['messages'])->toContain(35)->not->toContain(1);
});

test('nested thumbnail checkboxes can be enabled and disabled', function (bool $enabled) {
    $request = array('tab' => 'general');
    if ($enabled) {
        $request['thumbnail_section_tree_2'] = 'on';
    }
    $result = profile_setting_run('form_save', $request);
    $stored = array_column(array_column(admin_action_probe_writes($result, '/^set_user_setting$/'), 'params'), 1, 0);
    expect($stored['thumbnail_section_tree_2'])->toBe($enabled ? 'on' : '')
        ->and($result['session']['sess_error_fields'] ?? array())->not->toHaveKey('thumbnail_section_tree_2');
})->with(array(true,false));


test('profile autosave validates supported plugin fields before persistence', function (array $field, string $accepted, string $rejected) {
    foreach (array(array($accepted, true, true), array($rejected, true, false), array($accepted, false, false)) as [$value, $authorized, $stored]) {
        $result = profile_setting_run('api_auth_update_user_setting', array('name' => 'plugin_setting', 'value' => $value), $authorized, 'auth_profile.php', array('plugin_setting' => $field));
        $writes = admin_action_probe_writes($result, '/^REPLACE INTO settings_user/');
        expect($writes)->toHaveCount($stored ? 1 : 0);
        if ($stored) {
            expect($writes[0]['params'])->toBe(array('plugin_setting', $value, 5));
        }
    }
})->with(array(
    'callback choice' => array(array('method' => 'drop_callback', 'sql' => 'SELECT id,name FROM graph_tree'), '4', '99'),
    'radio choice' => array(array('method' => 'radio', 'items' => array(array('radio_value' => 'safe', 'radio_caption' => 'Safe'))), 'safe', 'absent'),
    'password length' => array(array('method' => 'textbox_password', 'max_length' => 8), "sample'", 'oversized'),
));

test('profile file fields accept only offered directory entries', function () {
    $directory = sys_get_temp_dir() . '/kadupul-profile-files-' . bin2hex(random_bytes(8));
    expect(mkdir($directory, 0700))->toBeTrue();
    try {
        expect(file_put_contents($directory . '/offered', 'fixture'))->toBe(7)
            ->and(file_put_contents($directory . '/excluded', 'fixture'))->toBe(7);
        $field = array('method' => 'drop_files', 'directory' => $directory, 'exclusions' => array('excluded'));
        foreach (array('offered', 'excluded', 'missing', '.', '..', '../offered') as $value) {
            $result = profile_setting_run('api_auth_update_user_setting', array('name' => 'plugin_file', 'value' => $value), true, 'auth_profile.php', array('plugin_file' => $field));
            $writes = admin_action_probe_writes($result, '/^REPLACE INTO settings_user/');
            expect($writes)->toHaveCount($value === 'offered' ? 1 : 0);
            if ($value === 'offered') {
                expect($writes[0]['params'])->toBe(array('plugin_file', 'offered', 5));
            }
        }
    } finally {
        foreach (array('offered', 'excluded') as $file) {
            if (is_file($directory . '/' . $file)) {
                unlink($directory . '/' . $file);
            }
        }
        rmdir($directory);
    }
});
