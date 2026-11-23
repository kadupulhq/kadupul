<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Fixed private fixture descriptor. No command field selects the script or mode.
$consoleHandoff=[
    'page'=>'user_group_admin.php','path'=>'page_path','suffix'=>'/user_group_admin.php',
    'keys'=>[], 'valid'=>static fn(array $command):bool=>true, 'realm'=>1,
    'run'=>static fn(array $command):array=>['outcome'=>'admitted'], 'json'=>0,
    'realm_denial'=>getenv('KADUPUL_BROWSER_DENIAL_OPT_IN')==='1',
];
require __DIR__.'/legacy-console-handoff.php';
