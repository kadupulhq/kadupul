<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Copied only into a task-owned candidate's include/config.php. Credentials
// remain in the existing native proof environment, never this fixture source.
$proofDsn = getenv('KADUPUL_REFERENCE_TEST_DSN');
$proofSchema = getenv('KADUPUL_REFERENCE_RUNTIME_SCHEMA');
if (!is_string($proofDsn) || !str_starts_with($proofDsn, 'mysql:') || !is_string($proofSchema)
    || preg_match('/^kadupul_cdef_install_[a-f0-9]{16}$/D', $proofSchema) !== 1) {
    throw new RuntimeException('An exclusively task-owned native installer fixture is required.');
}
$proofOptions = [];
foreach (explode(';', substr($proofDsn, 6)) as $part) {
    $pair = explode('=', $part, 2);
    if (count($pair) === 2) {
        $proofOptions[$pair[0]] = $pair[1];
    }
}
$database_type = 'mysql';
$database_default = $proofSchema;
$database_hostname = $proofOptions['unix_socket'] ?? $proofOptions['host'] ?? '';
$database_port = (int) ($proofOptions['port'] ?? 3306);
$database_username = getenv('KADUPUL_REFERENCE_TEST_USER') ?: '';
$database_password = getenv('KADUPUL_REFERENCE_TEST_PASSWORD') ?: '';
$database_ssl = false;
$poller_id = 1;
$url_path = '/fixture/';
$config['rrd_maintenance_trusted_uids'] = function_exists('posix_geteuid') ? [posix_geteuid()] : [];
$config['rrd_maintenance_trusted_gids'] = function_exists('posix_getegid') ? [posix_getegid()] : [];
