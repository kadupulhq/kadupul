<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Copied only to the marked candidate's include/config.php.
require __DIR__ . '/../tests/Fixtures/cdef-reference-runtime-config.php';

$proofPrimarySchema = getenv('KADUPUL_REFERENCE_PRIMARY_SCHEMA');
$proofCollectorMode = getenv('KADUPUL_REFERENCE_COLLECTOR_MODE');
if (!is_string($proofPrimarySchema)
    || preg_match('/^kadupul_cdef_install_[a-f0-9]{16}$/D', $proofPrimarySchema) !== 1
    || !in_array($proofCollectorMode, ['online', 'offline'], true)) {
    throw new RuntimeException('An exclusively owned collector/primary fixture is required.');
}
$poller_id = 2;
$rdatabase_type = $database_type;
$rdatabase_default = $proofPrimarySchema;
$rdatabase_hostname = $database_hostname;
$rdatabase_port = $database_port;
$rdatabase_username = $database_username;
$rdatabase_password = $database_password;
$rdatabase_ssl = false;
$rdatabase_retries = 1;
$conn_mode = $proofCollectorMode;
