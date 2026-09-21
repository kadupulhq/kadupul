<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\Kernel;

require_once dirname(__DIR__) . '/include/vendor/autoload.php';

$environment = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? (getenv('APP_ENV') ?: 'prod');
$debug = $_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? (getenv('APP_DEBUG') ?: '0');

return new Kernel($environment, filter_var($debug, FILTER_VALIDATE_BOOL));
