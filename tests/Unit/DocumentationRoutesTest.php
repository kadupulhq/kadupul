<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once __DIR__ . '/../../lib/documentation.php';

test('documentation links preserve topics and confine unrecognized input to the map', function () {
    expect(cacti_documentation_url('Graphs.html'))->toBe('https://kadupul.org/concepts/how-graphs-are-drawn/')
        ->and(cacti_documentation_url('Settings-Auth.html'))->toBe('https://kadupul.org/reference/settings/')
        ->and(cacti_documentation_url('https://attacker.example/'))->toBe('https://kadupul.org/map/')
        ->and(cacti_documentation_url('Unknown.html'))->toBe('https://kadupul.org/map/');
});


test('every built-in page-help entry has a contextual route', function () {
    $source = file_get_contents(__DIR__ . '/../../lib/html.php');
    $start = strpos($source, "'aggregates.php'");
    $end = strpos($source, '$help = api_plugin_hook_function', $start);
    preg_match_all("/=> '([^']+\\.html)'/", substr($source, $start, $end - $start), $matches);
    expect(count($matches[1]))->toBeGreaterThan(40);
    foreach ($matches[1] as $page) {
        expect(cacti_documentation_url($page))->not->toBe('https://kadupul.org/map/');
    }
});
