<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

$formSource = file_get_contents(__DIR__ . '/../../lib/html_form.php');
$start = strpos($formSource, 'function form_color_dropdown(');
$end = strpos($formSource, '\nfunction ', $start + 1);
$formFunction = substr($formSource, $start, $end - $start);

test('color dropdown encodes stored colors and form values for HTML', function () use ($formFunction) {
    expect($formFunction)->toContain('html_escape($current_color)');
    expect($formFunction)->toContain('html_escape($form_name)');
    expect($formFunction)->toContain('html_escape($class_name)');
    expect($formFunction)->toContain('html_escape($form_none_entry)');
    expect($formFunction)->toContain('(int) $color[\'id\']');
});

test('color dropdown does not print the current color or option ID raw', function () use ($formFunction) {
    expect($formFunction)->not->toContain('#$current_color;');
    expect($formFunction)->not->toContain('. $color[\'id\'] .');
});
