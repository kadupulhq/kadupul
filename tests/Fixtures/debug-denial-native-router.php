<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Owned loopback HTTP transport for the whole-controller fixture. Authentication,
// CSRF and persistence transport remain its explicitly documented boundaries.
define('UTILITY_NATIVE_HTTP_PORT', true);
define('STDOUT', fopen('php://output', 'w'));
$root = getenv('DEBUG_DENIAL_ROOT');
$directory = getenv('DEBUG_DENIAL_DIRECTORY');
$scenario = json_decode(file_get_contents($directory . '/scenario.json'), true, 512, JSON_THROW_ON_ERROR);
$argv = ['utility-view-native.php', json_encode($scenario, JSON_THROW_ON_ERROR), $directory . '/fixture'];
require $root . '/tests/Fixtures/utility-view-native.php';
