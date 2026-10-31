#!/usr/bin/env php
<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

// Forwarding shim: arguments, output and exit codes match the original script.
// The behavior now lives in bin/console kadupul:graph:remove; the frozen
// original, kept for parity, is tests/Fixtures/legacy-cli/remove_graphs.php.
if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(404);
    }
    exit;
}
require dirname(__DIR__) . '/include/vendor/autoload.php';
exit(\Kadupul\Platform\Infrastructure\Symfony\Console\LegacyCli::run(
    'kadupul:graph:remove',
    \Kadupul\Graphing\Infrastructure\Symfony\RemoveGraphsLegacyArguments::class,
    $_SERVER['argv'],
));
