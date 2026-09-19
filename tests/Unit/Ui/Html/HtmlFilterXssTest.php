<?php
// SPDX-FileCopyrightText: 2004-2026 The Cacti Group
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

test('selectable cell titles preserve quotes as data and cannot inject HTML', function ($alreadyEscaped) {
    $title = "\"' ><script>alert(1)</script>";
    ob_start();
    try { form_selectable_cell('visible', 1, '', '', $alreadyEscaped ? html_escape($title) : $title); $html = ob_get_contents(); } finally { ob_end_clean(); }
    $document = new DOMDocument();
    $document->loadHTML('<table><tr>' . $html . '</tr></table>');
    $span = $document->getElementsByTagName('span')->item(0);
    expect($span->getAttribute('title'))->toBe($title)
        ->and($document->getElementsByTagName('script')->length)->toBe(0);
})->with(array(false, true));

if (!class_exists('CactiSecureHeaders')) {
    class CactiSecureHeaders { public static function getNonceAttribute() { return ''; } }
}

test('filter JavaScript preserves hostile form IDs and encodes query values', function ($with_fields) {
    $class = new ReflectionClass(CactiTableFilter::class);
    $filter = $class->newInstanceWithoutConstructor();
    $filter->form_id = "form'\\</script><script>alert(1)</script>";
    $filter->form_action = '/filter.php?keep=1&title="</script>';
    $fields = $with_fields ? array('rows' => array(array('name' => array('method' => 'textbox'),
        'choice' => array('method' => 'drop_array'), 'enabled' => array('method' => 'checkbox')))) : array();
    $property = $class->getProperty('filter_array'); $property->setAccessible(true); $property->setValue($filter, $fields);
    $method = $class->getMethod('create_javascript'); $method->setAccessible(true);
    ob_start();
    try { $method->invoke($filter); $html = ob_get_contents(); } finally { ob_end_clean(); }
    expect(substr_count($html, '<script'))->toBe(1)->and(substr_count($html, '</script>'))->toBe(1);
    preg_match('/<script[^>]*>([\s\S]*)<\/script>/', $html, $match);
    $javascript = 'const urls=[], bindings=[], handlers={}; const document={getElementById:id=>({id})}; '
        . 'function $(arg){if(typeof arg==="function"){arg();return;} return {on:(event,fn)=>{bindings.push([arg.id || arg,event]);handlers[(arg.id || arg)+":"+event]=fn;},val:()=>"a & b=1",is:()=>true};} '
        . 'function loadPageNoHeader(url){urls.push(url);} ' . $match[1]
        . '\napplyFilter();clearFilter();if(handlers["enabled:change"])handlers["enabled:change"]();console.log(JSON.stringify({urls,bindings}));';
    $javascript = str_replace('\\napplyFilter', "\napplyFilter", $javascript);
    $process = proc_open(array(getenv('NODE_BINARY') ?: 'node', '-e', $javascript), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    expect($process)->not->toBeFalse();
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $stderr);
    $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    $base = $filter->form_action . '&header=false';
    $applied = $base . ($with_fields ? '&name=a%20%26%20b%3D1&choice=a%20%26%20b%3D1&enabled=true' : '');
    $expectedUrls = array($applied, $base . '&clear=true');
    if ($with_fields) { $expectedUrls[] = $applied; }
    expect($result['urls'])->toBe($expectedUrls)
        ->and($result['bindings'][0])->toBe(array($filter->form_id, 'submit'));
    if ($with_fields) { expect($result['bindings'])->toContain(array('choice', 'change'))->toContain(array('enabled', 'change'))->not->toContain(array('name', 'change')); }
})->with(array(false, true));
