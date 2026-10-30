#!/usr/bin/env php
<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

// Forwarding shim: arguments, output and exit codes match the original script.
// The behavior now lives in bin/console kadupul:graph:reapply-titles; the frozen
// original, kept for parity, is tests/Fixtures/legacy-cli/poller_graphs_reapply_names.php.
if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(404);
    }
    exit;
}
require dirname(__DIR__) . '/include/vendor/autoload.php';
exit(\Kadupul\Platform\Infrastructure\Symfony\Console\LegacyCli::run(
    'kadupul:graph:reapply-titles',
    \Kadupul\Graphing\Infrastructure\Symfony\ReapplyGraphTitlesLegacyArguments::class,
    $_SERVER['argv'],
));
