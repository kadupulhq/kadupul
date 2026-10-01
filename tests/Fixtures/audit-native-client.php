#!/usr/bin/env php
<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$database = getenv('AUDIT_TEST_CASE') === 'leading-hyphen' ? '-audit' : 'fixture database; echo ignored';
if (!in_array('--database=' . $database, $argv, true)
    || getenv('MYSQL_PWD') !== 'fixture password with quotes \" and spaces'
    || count($argv) !== 5
    || array_filter($argv, static fn($argument) => str_starts_with($argument, '-p') || str_contains($argument, 'fixture password'))) {
    exit(2);
}
$db = new PDO('sqlite:' . getenv('AUDIT_TEST_SQLITE'));
$sql = stream_get_contents(STDIN);
$case = getenv('AUDIT_TEST_CASE');
if ($case === 'partial-success') {
    $prefix = substr($sql, 0, strpos($sql, 'CREATE TABLE `audit_complete_'));
    $db->exec($prefix);
    exit(0);
}
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
