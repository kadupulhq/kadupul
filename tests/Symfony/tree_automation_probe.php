<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require dirname(__DIR__, 2) . '/include/cli_check.php';
require_once dirname(__DIR__, 2) . '/lib/api_automation.php';
require_once dirname(__DIR__, 2) . '/lib/api_tree.php';

if ($argc !== 3 || !ctype_digit($argv[1]) || !ctype_digit($argv[2])) {
    throw new InvalidArgumentException('Expected host and tree item IDs');
}

automation_add_tree((int) $argv[1], (int) $argv[2]);
