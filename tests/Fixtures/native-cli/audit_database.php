<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Exercise the native command without replacing the retained production CLI.
require dirname(__DIR__, 3) . '/include/vendor/autoload.php';
exit(\Kadupul\Platform\Infrastructure\Symfony\Console\LegacyCli::run(
    'kadupul:database:audit',
    \Kadupul\Platform\Infrastructure\Symfony\Console\AuditDatabaseLegacyArguments::class,
    $_SERVER['argv'],
));
