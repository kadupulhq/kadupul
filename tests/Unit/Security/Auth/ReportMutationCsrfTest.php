<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$root              = dirname(__DIR__, 4);
$reportsAdmin     = file_get_contents($root . '/reports_admin.php');
$reportsUser      = file_get_contents($root . '/reports_user.php');
$htmlReports      = file_get_contents($root . '/lib/html_reports.php');
$reportsGenerator = file_get_contents($root . '/lib/reports.php');

test('report mutation actions reject non-POST requests in both report controllers', function () use ($reportsAdmin, $reportsUser) {
    foreach (array('send', 'ajax_dnd', 'item_movedown', 'item_moveup', 'item_remove') as $action) {
        expect($reportsAdmin)->toMatch('/case\s+\'' . preg_quote($action, '/') . '\':\s+reports_require_post\(\'' . preg_quote($action, '/') . '\'\);/s');
        expect($reportsUser)->toMatch('/case\s+\'' . preg_quote($action, '/') . '\':\s+reports_require_post\(\'' . preg_quote($action, '/') . '\'\);/s');
    }
});

test('report item controls post mutations with the csrf token', function () use ($htmlReports) {
    expect($htmlReports)->toContain('function reports_require_post($action)')
        ->and($htmlReports)->toContain('loadPageUsingPost(reportsPage')
        ->and($htmlReports)->toContain('__csrf_magic:csrfMagicToken')
        ->and($htmlReports)->not->toContain('?action=item_movedown&item_id=')
        ->and($htmlReports)->not->toContain('?action=item_moveup&item_id=')
        ->and($htmlReports)->not->toContain('?action=item_remove&item_id=');
});

test('report controllers redirect mutations through the realm-aware reports page', function () use ($reportsAdmin, $reportsUser) {
    expect($reportsUser)->not->toContain("header('Location: reports_admin.php?action=edit&tab=items&id='")
        ->and($reportsAdmin)->toContain("header('Location: ' . get_reports_page()")
        ->and($reportsUser)->toContain("header('Location: ' . get_reports_page()");
});

test('report data query labels are escaped before generated html output', function () use ($reportsGenerator) {
    expect($reportsGenerator)->toContain("__('Data Query:') . ' ' . html_escape(\$data_query['name'])")
        ->and($reportsGenerator)->not->toContain("__('Data Query:') . ' ' . \$data_query['name']");
});

test('report image conversion uses unpredictable temporary files with cleanup', function () use ($reportsGenerator) {
    expect($reportsGenerator)->toContain("tempnam(sys_get_temp_dir(), 'cacti-report-')")
        ->and($reportsGenerator)->toContain('finally')
        ->and($reportsGenerator)->not->toContain("'/tmp/' . time() . '.png'");
});
