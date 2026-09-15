<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/*
 * Tests for SSRF hardening in help.php.
 *
 * Local document paths are reduced to a basename. Online help returns a
 * fixed destination without fetching a user-influenced URL.
 */

$helpPath = __DIR__ . '/../../help.php';

// --- help.php: local paths and the fixed online destination ---

test('help.php uses basename for page parameter', function () use ($helpPath) {
    $contents = file_get_contents($helpPath);

    expect($contents)->toContain('basename(');
});

test('help.php uses a fixed online destination without fetching a URL', function () use ($helpPath) {
    $contents = file_get_contents($helpPath);

    expect($contents)->toContain("'location' => cacti_documentation_url(\$page)");
    expect($contents)->not->toContain('cacti_http(');
    expect($contents)->not->toContain('file_get_contents(');
});
