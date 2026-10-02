<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// This probe executes real CLI entrypoints in a copied candidate, using the
// credential-free fixture config. Never point it at an existing installation.
$root = dirname(__DIR__, 2);
if (!is_file($root . '/.cdef-reference-task-owned-candidate')
    || hash_file('sha256', $root . '/include/config.php') !== hash_file('sha256', $root . '/tests/Fixtures/cdef-reference-runtime-config.php')) {
    throw new RuntimeException('A marked task-owned candidate with exact fixture configuration is required.');
}
$dsn = getenv('KADUPUL_REFERENCE_TEST_DSN');
if ($dsn === false || !str_starts_with($dsn, 'mysql:')) {
    throw new RuntimeException('An explicitly configured native installer probe DSN is required.');
}
$database = new PDO($dsn, getenv('KADUPUL_REFERENCE_TEST_USER') ?: '', getenv('KADUPUL_REFERENCE_TEST_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
]);
$schema = 'kadupul_cdef_install_' . bin2hex(random_bytes(8));
$environment = getenv();
$environment['KADUPUL_REFERENCE_RUNTIME_SCHEMA'] = $schema;
$created = false;

function installerAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS $message\n";
}

function installerSeed(PDO $database, string $root, ?string $schemaFile = null): void
{
    $delimiter = ';';
    $buffer = '';
    $source = file_get_contents($schemaFile ?? $root . '/cacti.sql');
    // The old source has an SPDX block followed by an unused DELIMITER //
    // directive, while all its statements still end with semicolons. Preserve
    // its actual SQL; current source's END$$ trigger boundaries stay effective.
    $source = preg_replace('/\A\s*\/\*.*?\*\/\s*/s', '', $source, 1);
    $terminalSource = preg_replace('/^DELIMITER \S+\h*$/m', '', $source);
    foreach (explode("\n", $source) as $line) {
        if (preg_match('/^DELIMITER (\S+)\s*$/', trim($line), $matches)) {
            if (trim($buffer) !== '') {
                throw new RuntimeException('Incomplete source schema statement at delimiter change.');
            }
            $declared = $matches[1];
            $delimiter = preg_match('/' . preg_quote($declared, '/') . '\h*$/m', $terminalSource) === 1 ? $declared : ';';
            continue;
        }
        if (str_starts_with(ltrim($line), '--') || trim($line) === '') {
            continue;
        }
        $buffer .= $line . "\n";
        if (str_ends_with(rtrim($buffer), $delimiter)) {
            $sql = substr(rtrim($buffer), 0, -strlen($delimiter));
            $database->exec($sql);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        throw new RuntimeException('Incomplete source schema statement after seed.');
    }
}

function installerRun(string $root, array $environment, string $entry, array $arguments): array
{
    $process = proc_open(
        [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'zend.exception_ignore_args=1', $root . '/' . $entry, ...$arguments],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes,
        $root,
        $environment
    );
    if (!is_resource($process)) {
        throw new RuntimeException('The real installer entrypoint could not be started.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    // Normal CLI output and argument-free exceptions contain no credentials.
    echo $output;
    echo $errors;

    return [$exit, $output, $errors];
}

try {
    echo 'SERVER ' . $database->query('SELECT VERSION()')->fetchColumn() . "\n";
    $database->exec("CREATE DATABASE `$schema`");
    $created = true;
    $database->exec("USE `$schema`");
    $mode = $argv[1] ?? 'fresh';
    $version = trim(file_get_contents($root . '/include/cacti_version'));
    if ($mode === 'upgrade') {
        $previousSchema = getenv('KADUPUL_REFERENCE_PREVIOUS_SCHEMA_FILE');
        if (!is_string($previousSchema) || !is_file($previousSchema)) {
            throw new RuntimeException('The pinned real 1.2.33 schema fixture is required for normal upgrade proof.');
        }
        installerSeed($database, $root, $previousSchema);
        $database->exec("UPDATE version SET cacti='1.2.33'");
        [$exit, $output, $errors] = installerRun($root, $environment, 'cli/upgrade_database.php', []);
        installerAssert(
            $exit === 0 && $errors === '' && str_contains($output, 'Upgrading from v1.2.33'),
            'actual normal CLI upgrade runs registered migrations from pinned real prior schema'
        );
    } elseif ($mode === 'fresh') {
        installerSeed($database, $root);
        installerAssert(
            $database->query('SELECT cacti FROM version')->fetchColumn() === 'new_install',
            'actual source schema begins a genuine fresh installer flow'
        );
        [$exit, $output, $errors] = installerRun($root, $environment, 'cli/install_cacti.php', ['--install', '--accept-eula', '--automationmode=0']);
        installerAssert($exit === 0 && $errors === '', 'actual normal fresh CLI Installer flow completes without force or bypass');
    } else {
        throw new RuntimeException('Unknown native normal installer probe mode.');
    }
    installerAssert(
        $database->query('SELECT cacti FROM version')->fetchColumn() === $version,
        'actual normal installer records current release only after completing work'
    );
    installerAssert(
        (int) $database->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME LIKE 'kadupul_cdef_%'")->fetchColumn() === 10
        && (int) $database->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME='kadupul_cdef_reference_status'")->fetchColumn() === 1,
        'actual normal installer installs all ten CDEF guards and status routine'
    );
    require $root . '/lib/cdef_reference.php';
    installerAssert(
        (new \Kadupul\Platform\Infrastructure\Legacy\CdefReferenceContract($database, 1))->ready(),
        'actual normal installer output passes exact native service and data verification'
    );
    echo "PASS actual normal $mode installer probe complete\n";

} finally {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
    if ($created) {
        $database->exec("DROP DATABASE `$schema`");
    }
}
