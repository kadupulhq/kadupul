<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

// Compatibility entry only. Symfony owns the console request and Twig view.
$kernel = require __DIR__ . '/config/bootstrap.php';
\Kadupul\Platform\Infrastructure\Symfony\LegacyPageForwarder::run($kernel, __DIR__, 'app.php/console');
