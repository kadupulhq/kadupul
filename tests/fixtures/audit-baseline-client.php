#!/usr/bin/env php
<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if ($argv[1] === '--version') {
    echo getenv('AUDIT_TEST_CLIENT_FAMILY') === 'mysql' ? 'mysql Ver 8.4.0' : 'mariadb Ver 10.11.8-MariaDB';
    exit(0);
}
$database = 'fixture database; echo ignored';
$ssl = getenv('AUDIT_TEST_CLIENT_SSL') === '1';
$expected_option = getenv('AUDIT_TEST_CLIENT_FAMILY') === 'mysql' ? '--ssl-mode=DISABLED' : '--skip-ssl';
if (count($argv) !== ($ssl ? 3 : 4) || !str_starts_with($argv[1], '--defaults-extra-file=')
    || (!$ssl && $argv[2] !== $expected_option) || end($argv) !== '--database=' . $database) {
    exit(2);
}
$defaults = substr($argv[1], strlen('--defaults-extra-file='));
if ((fileperms($defaults) & 0777) !== 0600 || !str_contains(file_get_contents($defaults), 'password=')) {
    exit(3);
}
file_put_contents(dirname(__FILE__) . '/credential-path', $defaults);
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
    fwrite(STDERR, 'Fixture SQL import was rejected: ' . getenv('AUDIT_TEST_DIAGNOSTIC_SECRET') . str_repeat('Z', 6000));
    exit(1);
}
$db->exec($sql);
exit;
