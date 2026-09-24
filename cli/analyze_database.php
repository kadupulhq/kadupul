#!/usr/bin/env php
<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

// Forwarding shim: flags, output and exit codes match the original script.
// The behavior now lives in bin/console kadupul:database:analyze; the frozen
// original, kept for parity, is tests/Fixtures/legacy-cli/analyze_database.php.
if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(404);
    }
    exit;
}
require dirname(__DIR__) . '/include/vendor/autoload.php';
exit(\Kadupul\Platform\Infrastructure\Symfony\Console\LegacyCli::run(
    'kadupul:database:analyze',
    \Kadupul\Platform\Infrastructure\Symfony\Console\AnalyzeDatabaseLegacyArguments::class,
    $_SERVER['argv'],
));
