<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

function get_selected_theme() { return $GLOBALS['render_value']; }
function read_config_option($name) { return $GLOBALS['render_value']; }
function get_nfilter_request_var($name) { return $GLOBALS['render_value']; }

/** Evaluate the actual template expression, with only external settings stubbed. */
function rendered_js_variable($path, $variable, $value) {
    $source = file_get_contents(dirname(__DIR__, 4) . '/' . $path);
    $pattern = '/var\s+' . preg_quote($variable, '/') . '\s*=\s*<\?php\s+print\s+(.*?);\s*\?>;/';
    if (!preg_match($pattern, $source, $match)) {
        throw new RuntimeException('Missing encoded template variable: ' . $variable);
    }
    $GLOBALS['render_value'] = $value;
    $action = $page = $value;
    return eval('return ' . $match[1] . ';');
}

test('profile and graph template strings remain data for hostile and Unicode values', function ($path, $variable) {
    foreach (array("'\"\\\n", '</script><script>alert(1)</script>', 'fr-CA & 日本語', '', 'modern') as $value) {
        $encoded = rendered_js_variable($path, $variable, $value);
        expect(json_decode($encoded, true, 512, JSON_THROW_ON_ERROR))->toBe($value)
            ->and($encoded)->not->toContain('<')->not->toContain("\n");
    }
})->with(array(
    array('auth_profile.php', 'currentTab'),
    array('auth_profile.php', 'currentTheme'),
    array('auth_profile.php', 'currentLang'),
    array('auth_profile.php', 'authMethod'),
    array('lib/html_graph.php', 'pageAction'),
    array('lib/html_graph.php', 'graphPage'),
));

test('password return destinations are encoded as HTML attributes before the click handler reads them', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/auth_changepassword.php');
    expect($source)->toContain("data-location='<?php print html_escape(\$return);?>'")
        ->and($source)->toContain("var url = $(this).data('location');")
        ->and($source)->toContain('document.location = url;');
});
