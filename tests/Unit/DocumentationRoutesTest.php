<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require_once __DIR__ . '/../../lib/documentation.php';

test('documentation links preserve topics and confine unrecognized input to the map', function () {
    expect(cacti_documentation_url('Graphs.html'))->toBe('https://kadupul.org/concepts/how-graphs-are-drawn/')
        ->and(cacti_documentation_url('Settings-Auth.html'))->toBe('https://kadupul.org/reference/settings/')
        ->and(cacti_documentation_url('https://attacker.example/'))->toBe('https://kadupul.org/map/')
        ->and(cacti_documentation_url('Unknown.html'))->toBe('https://kadupul.org/map/');
});
