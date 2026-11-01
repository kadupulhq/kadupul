#!/usr/bin/env php
<?php

declare(strict_types=1);
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../include/runtime.php';
require __DIR__ . '/../include/vendor/autoload.php';
exit(\Kadupul\Platform\Infrastructure\Symfony\Console\LegacyCli::run(
    'kadupul:install',
    \Kadupul\Platform\Infrastructure\Symfony\Console\InstallerLegacyArguments::class,
    $_SERVER['argv'],
));
