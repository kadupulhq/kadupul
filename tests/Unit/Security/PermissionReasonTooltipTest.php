<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// get_permission_string() puts escaped group, device and template names in a
// data-tooltip attribute. Reading the attribute decodes them, so the page's
// tooltip callback has to hand jQuery UI text rather than markup.

function permission_tooltip_callbacks(string $file): array
{
    $source = file_get_contents(dirname(__DIR__, 3) . '/' . $file);

    preg_match_all("/items: '\\[data-tooltip\\]',\\s*content: function\\(\\) \\{(.*?)\\n\\t+\\}/s", $source, $matches);

    return $matches[1];
}

dataset('permission pages', array('user_admin.php', 'user_group_admin.php'));

test('permission reason tooltips return the decoded reason as escaped text', function ($file) {
    $callbacks = permission_tooltip_callbacks($file);

    expect($callbacks)->toHaveCount(1);
    expect($callbacks[0])->toContain("return $('<div>').text($(this).attr('data-tooltip')).html();");
    expect($callbacks[0])->not->toMatch("/return \\$\\(this\\)\\.attr\\('data-tooltip'\\);/");
})->with('permission pages');
