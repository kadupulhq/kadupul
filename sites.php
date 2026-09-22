<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Symfony\Component\HttpFoundation\Request;

// Compatibility entry point only. Symfony owns authorization and every workflow.
$kernel = require __DIR__ . '/config/bootstrap.php';
$original = Request::createFromGlobals();
$base = rtrim(str_replace('\\', '/', dirname($original->getBaseUrl())), '/.');
$server = $original->server->all();
$server['SCRIPT_FILENAME'] = __DIR__ . '/app.php';
$server['SCRIPT_NAME'] = $base . '/app.php';
$server['PHP_SELF'] = $base . '/app.php/inventory/sites/legacy';
$server['REQUEST_URI'] = $base . '/app.php/inventory/sites/legacy';
$request = $original->duplicate(null, null, null, null, null, $server);
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
