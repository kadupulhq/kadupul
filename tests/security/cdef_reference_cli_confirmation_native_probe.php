<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Real CLI entrypoints, prior-release SQL and two exclusively owned schemas.
$root = dirname(__DIR__, 2);
$configuration = $root . '/include/config.php';
$original = file_get_contents($configuration);
if (!is_file($root . '/.cdef-reference-task-owned-candidate') || $original === false
    || hash('sha256', $original) !== hash_file('sha256', $root . '/tests/Fixtures/cdef-reference-runtime-config.php')) {
    throw new RuntimeException('An exact marked native CLI candidate is required.');
}
require_once $root . '/tests/Helpers/PhpSource.php';
$normalProbe = file_get_contents($root . '/tests/security/cdef_reference_normal_installer_native_probe.php');
if ($normalProbe === false) {
    throw new RuntimeException('The existing real installer fixture is unavailable.');
}
foreach (['installerAssert', 'installerSeed', 'installerRun'] as $function) {
    eval(test_php_function_source($normalProbe, $function));
}
$dsn = getenv('KADUPUL_REFERENCE_TEST_DSN');
$previousSchema = getenv('KADUPUL_REFERENCE_PREVIOUS_SCHEMA_FILE');
if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:') || !is_string($previousSchema)
    || hash_file('sha256', $previousSchema) !== '6f17f3462837d028c5e67a7b9ae963caa11f716f65036152431c316482284184') {
    throw new RuntimeException('A native server and exact previous-release schema are required.');
}
$database = new PDO($dsn, getenv('KADUPUL_REFERENCE_TEST_USER') ?: '', getenv('KADUPUL_REFERENCE_TEST_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
]);
$schema = 'kadupul_cdef_install_' . bin2hex(random_bytes(8));
$primary = 'kadupul_cdef_install_' . bin2hex(random_bytes(8));
$created = [];
$environment = getenv();
$environment['KADUPUL_REFERENCE_RUNTIME_SCHEMA'] = $schema;
$environment['KADUPUL_REFERENCE_PRIMARY_SCHEMA'] = $primary;
$mode = $argv[1] ?? '';
$version = trim(file_get_contents($root . '/include/cacti_version'));

function cliFixtureSnapshot(PDO $database, string $schema): array
{
    $database->exec("USE `$schema`");
    $tables = $database->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    sort($tables, SORT_STRING);
    $structure = [];
    foreach ($tables as $table) {
        $structure[$table] = $database->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_NUM)[1];
    }
    return [
        $structure,
        $database->query('SELECT cacti FROM version')->fetchAll(PDO::FETCH_COLUMN),
        $database->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME")->fetchAll(PDO::FETCH_COLUMN),
    ];
}

try {
    foreach ([$schema, $primary] as $name) {
        $database->exec("CREATE DATABASE `$name`");
        $created[] = $name;
        $database->exec("USE `$name`");
        installerSeed($database, $root, $previousSchema);
        $database->exec("UPDATE version SET cacti='1.2.33'");
    }
    $beforeLocal = cliFixtureSnapshot($database, $schema);
    $beforePrimary = cliFixtureSnapshot($database, $primary);
    $database->exec("USE `$schema`");
    if (str_starts_with($mode, 'collector-')) {
        $environment['KADUPUL_REFERENCE_COLLECTOR_MODE'] = $mode === 'collector-offline' ? 'offline' : 'online';
        $collector = file_get_contents($root . '/tests/Fixtures/cdef-reference-collector-runtime-config.php');
        if ($collector === false || file_put_contents($configuration, $collector) !== strlen($collector)) {
            throw new RuntimeException('Cannot configure the owned collector fixture.');
        }
        $arguments = $mode === 'collector-local' ? ['--local'] : [];
        [$exit, $output, $errors] = installerRun($root, $environment, 'cli/upgrade_database.php', $arguments);
        $localAfter = cliFixtureSnapshot($database, $schema);
        $primaryAfter = cliFixtureSnapshot($database, $primary);
        echo 'OBSERVED collector exit=' . $exit . ' local=' . implode(',', $localAfter[1])
            . ' primary=' . implode(',', $primaryAfter[1]) . ' primary_triggers=' . count($primaryAfter[2]) . "\n";
        if ($mode === 'collector-online') {
            installerAssert(
                $exit !== 0 && str_contains($errors, 'Run schema upgrades from the primary collector'),
                'online collector refuses primary upgrade before migrations'
            );
            installerAssert(
                $beforeLocal === $localAfter && $beforePrimary === $primaryAfter,
                'refused collector preserves both actual schemas and release markers'
            );
        } else {
            installerAssert($exit === 0 && $localAfter[1] === [$version], 'explicit local/offline collector upgrade remains supported');
            installerAssert($beforePrimary === $primaryAfter, 'local/offline upgrade never changes the primary schema');
        }
    } else {
        $database->exec("USE `$schema`");
        if ($mode === 'marker-refusal') {
            $database->exec('CREATE TRIGGER cli_version_refusal BEFORE UPDATE ON version FOR EACH ROW BEGIN IF NEW.cacti = '
                . $database->quote($version) . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='native final marker refused'; END IF; END");
        } elseif ($mode === 'marker-coercion') {
            $database->exec('CREATE TRIGGER cli_version_refusal BEFORE UPDATE ON version FOR EACH ROW SET NEW.cacti = '
                . $database->quote('1.2.35'));
        } elseif ($mode !== 'marker-success') {
            throw new RuntimeException('Unknown native CLI confirmation mode.');
        }
        [$exit] = installerRun($root, $environment, 'cli/upgrade_database.php', []);
        $stored = $database->query('SELECT cacti FROM version')->fetchAll(PDO::FETCH_COLUMN);
        echo 'OBSERVED marker exit=' . $exit . ' version=' . implode(',', $stored) . "\n";
        if ($mode === 'marker-success') {
            installerAssert($exit === 0 && $stored === [$version], 'primary CLI confirms the genuine final version');
        } else {
            installerAssert($exit !== 0 && $stored === ['1.2.33'], 'failed or coerced CLI final marker refuses success and preserves retry');
            $database->exec('DROP TRIGGER cli_version_refusal');
            [$retry] = installerRun($root, $environment, 'cli/upgrade_database.php', []);
            installerAssert(
                $retry === 0 && $database->query('SELECT cacti FROM version')->fetchAll(PDO::FETCH_COLUMN) === [$version],
                'same CLI succeeds on a genuine repaired retry'
            );
        }
        installerAssert(
            (int) $database->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME LIKE 'kadupul_cdef_%'")->fetchColumn() === 10,
            'primary final marker follows all ten native reference guards'
        );
    }
} finally {
    $cleanupFailed = false;
    if (file_put_contents($configuration, $original) !== strlen($original)) {
        $cleanupFailed = true;
    }
    foreach ($created as $name) {
        try {
            if ($database->exec("DROP DATABASE `$name`") === false) {
                $cleanupFailed = true;
            }
        } catch (Throwable) {
            $cleanupFailed = true;
        }
    }
    if ($cleanupFailed) {
        throw new RuntimeException('Owned native CLI fixture cleanup could not be confirmed.');
    }
}
