<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Fixture-only router: invoke the real installer entries with their normal
// working directory and URL prefix. It does not authenticate requests.
$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$entries = [
    '/fixture/install/' => 'install/install.php',
    '/fixture/install/install.php' => 'install/install.php',
    '/fixture/install/step_json.php' => 'install/step_json.php',
];
if (!is_file($root . '/.cdef-reference-task-owned-candidate') || !isset($entries[$path])) {
    http_response_code(404);
    return;
}
$entry = $root . '/' . $entries[$path];
$_SERVER['SCRIPT_FILENAME'] = $entry;
$_SERVER['SCRIPT_NAME'] = '/fixture/' . $entries[$path];
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
chdir(dirname($entry));
require $entry;
