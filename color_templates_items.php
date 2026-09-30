<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

$kernel = require __DIR__ . '/config/bootstrap.php';
\Kadupul\Platform\Infrastructure\Symfony\LegacyPageForwarder::run($kernel, __DIR__, 'app.php/graphing/color-template-items/legacy');
