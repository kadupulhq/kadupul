<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace EditorOutputBatchTest;

function get_request_var($name, $default = '') {
    return $GLOBALS['editor_present'] ? $GLOBALS['editor_payload'] : $default;
}
function isset_request_var($name) { return $GLOBALS['editor_present']; }
function html_escape($value) {
    return htmlspecialchars(str_replace('`', '&#96;', $value), ENT_QUOTES | ENT_HTML5,
        ini_get('default_charset') ?: 'UTF-8', false);
}

$baseline = json_decode(file_get_contents(__DIR__ . '/editor-output-alert-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
$groups = array();
foreach ($baseline['issues'] as $issue) {
    $groups[$issue['context']][$issue['key']] = array($issue);
}
foreach ($groups as $context => $cases) {
    dataset('editor ' . $context, $cases);
}
dataset('editor text payloads', array(
    'ordinary' => array('on'),
    'unicode' => array('café'),
    'quotes' => array('\'" autofocus onfocus="alert(1)'),
    'elements' => array('</script><script>alert(1)</script><img src=x onerror=alert(1)>'),
    'entities' => array('&amp;#39;&#39;&quot;&amp;'),
    'backticks' => array('a`b\\c'),
    'query' => array('&id=42&header=false'),
    'empty' => array(''),
));

function fragment($issue) {
    $source = file_get_contents(dirname(__DIR__, 4) . '/' . $issue['file']);
    $position = strpos($source, $issue['after']);
    expect($position)->not->toBeFalse();
    return substr($source, $position, strlen($issue['after']));
}

function render($code, $payload, $present = true) {
    $GLOBALS['editor_payload'] = $payload;
    $GLOBALS['editor_present'] = $present;
    $graph_start = $payload;
    $graph_end = $payload;
    $id = $payload;
    ob_start();
    try {
        eval('namespace EditorOutputBatchTest; ?>' . $code);
        return ob_get_contents();
    } finally {
        ob_end_clean();
        unset($GLOBALS['editor_payload'], $GLOBALS['editor_present']);
    }
}

test('inventory contains 25 distinct open baseline findings and all production occurrences', function () use ($baseline) {
    expect(count($baseline['issues']))->toBe(25);
    expect(count(array_unique(array_column($baseline['issues'], 'key'))))->toBe(25);
    $counts = array();
    foreach ($baseline['issues'] as $issue) {
        fragment($issue);
        $key = $issue['file'] . "\n" . $issue['after'];
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
    foreach ($counts as $key => $count) {
        list($file, $code) = explode("\n", $key, 2);
        expect(substr_count(file_get_contents(dirname(__DIR__, 4) . '/' . $file), $code))->toBe($count);
    }
});

test('numeric output preserves canonical IDs and timestamps', function ($issue, $number) {
    expect(render(fragment($issue), $number))->toBe(render($issue['before'], $number));
})->with('editor numeric')->with(array(0, 1, 42, 2147483647, -3600, -1));

test('absent request IDs render zero without changing independently computed timestamps', function ($issue) {
    $isTimestamp = strpos($issue['before'], '$graph_start') !== false
        || strpos($issue['before'], '$graph_end') !== false;
    // Match get_request_var's actual empty-string default for absent request keys.
    // Local graph timestamps are computed separately and must not depend on ID presence.
    $output = render(fragment($issue), -3600, false);
    expect($output)->toBe(render($issue['before'], $isTimestamp ? -3600 : 0, true));
})->with('editor numeric');

test('empty and null numeric values render a safe zero', function ($issue, $empty) {
    expect(render(fragment($issue), $empty))->toBe(render($issue['before'], 0));
})->with('editor numeric')->with(array('', null));

test('numeric contexts cannot emit markup or executable tokens', function ($issue, $payload) {
    $output = render(fragment($issue), $payload);
    // The only changed bytes are the output values: surrounding JS/HTML is identical.
    expect($output)->toBe(render($issue['before'], (int)$payload));
    expect($output)->not->toContain('<script', 'onerror', 'onfocus', 'alert(');
})->with('editor numeric')->with('editor text payloads');

test('thumbnail strings retain charset and pre-escaped values inside a single hidden input',
    function ($issue, $payload, $charset) {
        $previous = ini_get('default_charset');
        ini_set('default_charset', $charset);
        try {
            $effective = $charset ?: 'UTF-8';
            $bytes = mb_convert_encoding($payload, $effective, 'UTF-8');
            $html = render(fragment($issue), $bytes);
            expect($html)->toBe(render($issue['before'], $bytes));
            $previousErrors = libxml_use_internal_errors(true);
            try {
                $doc = new \DOMDocument();
                $doc->loadHTML('<html><head><meta charset="' . $effective . '"></head><body>' . $html . '</body></html>');
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previousErrors);
            }
            expect($doc->getElementsByTagName('script')->length)->toBe(0);
            expect($doc->getElementsByTagName('img')->length)->toBe(0);
            expect($doc->getElementsByTagName('input')->length)->toBe(1);
            $input = $doc->getElementsByTagName('input')->item(0);
            expect($input->attributes->length)->toBe(3);
            expect($input->getAttribute('type'))->toBe('hidden');
            expect($input->getAttribute('id'))->toBe('thumbnails');
        } finally {
            ini_set('default_charset', $previous);
        }
    })->with('editor html-string')->with('editor text payloads')->with(array('UTF-8', 'ISO-8859-1', ''));

test('editor navigation string round-trips with no HTML script delimiters', function ($issue, $payload) {
    $output = render(fragment($issue), $payload);
    expect(preg_match('/^strURL = ("(?:\\\\.|[^"\\\\])*") \+$/', $output, $match))->toBe(1);
    expect(json_decode($match[1], true, 512, JSON_THROW_ON_ERROR))
        ->toBe('graphs_items.php?header=false&action=item_edit' . $payload);
    expect($match[1])->not->toContain('<', '>', '&', "'");
})->with('editor json')->with('editor text payloads');
