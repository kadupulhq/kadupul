<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later
require_once dirname(__DIR__, 4) . '/lib/functions.php';
require_once dirname(__DIR__, 4) . '/lib/html_utility.php';
require_once dirname(__DIR__, 4) . '/lib/html_filter.php';
require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
eval(test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/html.php'), 'html_escape'));
function html_start_box(...$args) {}
function html_end_box(...$args) {}

test('filter form attributes round-trip quotes tags and Unicode without creating attributes', function ($value) {
    $reflection = new ReflectionClass(CactiTableFilter::class);
    $filter = $reflection->newInstanceWithoutConstructor();
    $filter->form_id = $filter->form_action = $value;
    $filter->default_filter = array('rows' => array());
    $method = $reflection->getMethod('create_filter');
    $method->setAccessible(true);
    ob_start();
    try { $method->invoke($filter); $html = ob_get_contents(); } finally { ob_end_clean(); }
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="UTF-8">' . $html);
    $form = $document->getElementsByTagName('form')->item(0);
    expect($form->getAttribute('id'))->toBe($value)
        ->and($form->getAttribute('action'))->toBe($value)
        ->and($form->attributes->length)->toBe(2)
        ->and($document->getElementsByTagName('script')->length)->toBe(0);
})->with(array("a' onfocus='alert(1)", 'a" onfocus="alert(1)', '<script>alert(1)</script>', 'français 日本語 & filter'));

test('selectable cell titles preserve quotes as data and cannot inject HTML', function () {
    $title = "\"' ><script>alert(1)</script>";
    ob_start();
    try { form_selectable_cell('visible', 1, '', '', $title); $html = ob_get_contents(); } finally { ob_end_clean(); }
    $document = new DOMDocument();
    $document->loadHTML('<table><tr>' . $html . '</tr></table>');
    $span = $document->getElementsByTagName('span')->item(0);
    expect($span->getAttribute('title'))->toBe($title)
        ->and($document->getElementsByTagName('script')->length)->toBe(0);
});
