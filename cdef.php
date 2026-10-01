<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

use Symfony\Component\HttpFoundation\Request;

$kernel = require __DIR__ . '/config/bootstrap.php';
$original = Request::createFromGlobals();

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    http_response_code(409);
    header('Cache-Control: private, no-store');
    echo 'CDEF changes must be submitted through the Symfony forms. Reload the page and try again.';
    return;
}

$base = rtrim(str_replace('\\', '/', dirname($original->getBaseUrl())), '/.');
$server = $original->server->all();
$server['SCRIPT_FILENAME'] = __DIR__ . '/app.php';
$server['SCRIPT_NAME'] = $base . '/app.php';
$server['PHP_SELF'] = $base . '/app.php/graph-definitions/cdefs/legacy';
$server['REQUEST_URI'] = $base . '/app.php/graph-definitions/cdefs/legacy';
$request = $original->duplicate(null, null, null, null, null, $server);
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
