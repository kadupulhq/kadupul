<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

// Compatibility entry point only. Symfony owns authorization and every workflow.
$kernel = require __DIR__ . '/config/bootstrap.php';
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    http_response_code(409);
    header('Cache-Control: private, no-store');
    echo 'VDEF changes must be submitted through the Symfony forms. Reload the page and try again.';
    return;
}

\Kadupul\Platform\Infrastructure\Symfony\LegacyPageForwarder::run($kernel, __DIR__, 'app.php/graph-definitions/vdefs/legacy');
