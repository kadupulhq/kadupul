<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

// The existing authentication flow has a dedicated compatibility entry now
// that index.php belongs to Symfony. Password/LDAP/Basic/remembered login and
// CSRF remain owned by include/auth.php until IdentityAccess migrates them.
require __DIR__ . '/include/auth.php';
header('Location: ' . $config['url_path'] . 'index.php', true, 303);
