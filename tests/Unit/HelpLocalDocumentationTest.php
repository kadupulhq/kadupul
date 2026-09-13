<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Local page help serves the HTML documentation the Local Page Help Only
 * setting tells administrators to host under docs/. The callers in
 * lib/html.php and install/install.php request .html names, so help.php must
 * look up the name it was given rather than a .md file that is never shipped.
 */

$helpSource     = file_get_contents(__DIR__ . '/../../help.php');
$settingsSource = file_get_contents(__DIR__ . '/../../include/global_settings.php');

test('help.php looks up the requested page name without rewriting .html to .md', function () use ($helpSource) {
    expect($helpSource)->not->toContain("'.md'");
    expect($helpSource)->toContain("\$page = basename(get_request_var('page'));");
    expect($helpSource)->toContain("file_exists(\$config['base_path'] . '/docs/' . \$page)");
});

test('the local documentation setting describes HTML hosted under docs', function () use ($settingsSource) {
    $start = strpos($settingsSource, "'local_documentation' => array(");
    $body  = substr($settingsSource, $start, 600);

    expect($body)->toContain('in HTML format');
    expect($body)->toContain("\\'docs\\' location");
});

test('help callers request HTML page names', function () {
    $html    = file_get_contents(__DIR__ . '/../../lib/html.php');
    $install = file_get_contents(__DIR__ . '/../../install/install.php');

    expect($html)->toContain("'aggregates.php'              => 'Aggregates.html'");
    expect($install)->toContain("\$help = 'Upgrading-Cacti.html';");
});
