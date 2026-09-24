#!/usr/bin/env php
<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

// Forwarding shim: flags, output and exit codes match the original script.
// The behavior now lives in bin/console kadupul:database:convert-tables; the
// frozen original, kept for parity, is tests/Fixtures/legacy-cli/convert_tables.php.
// lib/installer.php runs this file for each table it queued and reads its stdout.
if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(404);
    }
    exit;
}
require dirname(__DIR__) . '/include/vendor/autoload.php';
exit(\Kadupul\Platform\Infrastructure\Symfony\Console\LegacyCli::run(
    'kadupul:database:convert-tables',
    \Kadupul\Platform\Infrastructure\Symfony\Console\ConvertTablesLegacyArguments::class,
    $_SERVER['argv'],
));
