<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('fixed graph line types do not present an editable width control', function () {
    $root = dirname(__DIR__, 4);
    $graphItems = file_get_contents($root . '/graphs_items.php');
    $templateItems = file_get_contents($root . '/graph_templates_items.php');
    $forms = file_get_contents($root . '/include/global_form.php');

    foreach (array($graphItems, $templateItems) as $source) {
        $fixedTypes = substr($source, strpos($source, "case '4': // LINE1"));
        $fixedTypes = substr($fixedTypes, 0, strpos($fixedTypes, 'break;'));
        expect($fixedTypes)->toContain("$('#row_line_width').hide();")
            ->and($fixedTypes)->not->toContain("$('#row_line_width').show();");

        $stack = substr($source, strpos($source, "case '20': // LINE:STACK"));
        $stack = substr($stack, 0, strpos($stack, 'break;'));
        preg_match_all('/\$\(\x27#row_[a-z_]+\x27\)\.(?:show|hide)\(\);/', $fixedTypes, $fixedRows);
        preg_match_all('/\$\(\x27#row_[a-z_]+\x27\)\.(?:show|hide)\(\);/', $stack, $stackRows);
        expect(substr_count($fixedTypes, "$('#row_line_width')"))->toBe(1)
            ->and(substr_count($stack, "$('#row_line_width')"))->toBe(1)
            ->and(array_values(array_diff($fixedRows[0], array("$('#row_line_width').hide();"))))
            ->toBe(array_values(array_diff($stackRows[0], array("$('#row_line_width').show();"))));
    }

    $stackType = substr($graphItems, strpos($graphItems, "case '20': // LINE:STACK"));
    $stackType = substr($stackType, 0, strpos($stackType, 'break;'));
    $templateStackType = substr($templateItems, strpos($templateItems, "case '20': // LINE:STACK"));
    $templateStackType = substr($templateStackType, 0, strpos($templateStackType, 'break;'));
    expect($stackType)->toContain("$('#row_line_width').show();")
        ->and($templateStackType)->toContain("$('#row_line_width').show();")
        ->and($forms)->toContain('LINE1, LINE2 and LINE3 use fixed widths')
        ->and($forms)->toContain('integers or decimal values are supported.');
});
