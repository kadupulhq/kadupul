<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Symfony\Component\HttpFoundation\Request;

// Keep legacy bootstrap in global scope: it owns login, cookies and sessions.
require __DIR__ . '/include/global.php';
$user_auth_realm_filenames['app.php'] = 8;
require __DIR__ . '/include/auth.php';

define('KADUPUL_AUTHENTICATED_ENTRY', true);

$kernel = require __DIR__ . '/config/bootstrap.php';
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
