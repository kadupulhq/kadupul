#!/usr/bin/env php
<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (!in_array('fixture database; echo ignored', $argv, true)) {
    exit(2);
}
$db = new PDO('sqlite:' . getenv('AUDIT_TEST_SQLITE'));
$sql = stream_get_contents(STDIN);
$case = getenv('AUDIT_TEST_CASE');
if ($case === 'partial-import') {
    $statements = explode(';', $sql);
    $db->exec($statements[0]);
    $db->exec($statements[1]);
    exit(1);
}
if ($case === 'import-failure') {
    exit(1);
}
$db->exec($sql);
exit;
