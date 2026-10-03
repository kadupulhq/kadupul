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

function installerSeed(PDO $database, string $root): void
{
    $delimiter = ';';
    $buffer = '';
    foreach (file($root . '/cacti.sql') as $line) {
        if (preg_match('/^DELIMITER (\S+)\s*$/', trim($line), $matches)) {
            if (trim($buffer) !== '') {
                throw new RuntimeException('Incomplete source schema statement at delimiter change.');
            }
            $delimiter = $matches[1];
            continue;
        }
        if (str_starts_with(ltrim($line), '--') || trim($line) === '') {
            continue;
        }
        $buffer .= $line;
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
    require_once $root . '/tests/Helpers/CdefCliCoverageRegistration.php';
    [$prepend, $environment, $coverageReceipt] = CdefCliCoverageRegistration::invocation($root, $environment, $entry, $arguments);
    $process = proc_open(
        [PHP_BINARY, '-d', 'auto_prepend_file=' . $prepend, '-d', 'zend.exception_ignore_args=1',
            ...($coverageReceipt !== null ? ['-d', 'pcov.enabled=1', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~'] : []),
            $root . '/' . $entry, ...$arguments],
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
    CdefCliCoverageRegistration::receipt($coverageReceipt, $exit, $output, $errors);
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
    installerSeed($database, $root);
    $version = trim(file_get_contents($root . '/include/cacti_version'));
    $statement = $database->prepare('UPDATE version SET cacti=?');
    $statement->execute([$version]);
    $beforeItems = $database->query('SELECT * FROM cdef_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    [$exit, $output, $errors] = installerRun($root, $environment, 'cli/upgrade_database.php', ['--install-cdef-reference-contract']);
    installerAssert(
        $exit === 0 && $errors === '' && str_contains($output, 'Primary CDEF reference contract installed and exact metadata verified.'),
        'actual current-version CLI installs contract before version and RRD early exits'
    );
    installerAssert(
        $database->query('SELECT cacti FROM version')->fetchColumn() === $version
        && $database->query('SELECT * FROM cdef_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $beforeItems,
        'actual explicit CLI preserves current version and every existing CDEF item'
    );
    [$exit, $output, $errors] = installerRun($root, $environment, 'cli/upgrade_database.php', ['--install-cdef-reference-contract']);
    installerAssert($exit === 0 && $errors === '', 'actual current-version CLI repeated installation is idempotent');
    [$exit] = installerRun($root, $environment, 'cli/upgrade_database.php', ['--install-cdef-reference-contract', '--local']);
    installerAssert($exit === 1, 'actual schema CLI refuses combined collector maintenance option');
    $database->exec('DROP TRIGGER kadupul_cdef_graph_templates_item_insert');
    $database->exec('INSERT INTO graph_templates_item (cdef_id) VALUES (16777215)');
    [$exit] = installerRun($root, $environment, 'cli/upgrade_database.php', ['--install-cdef-reference-contract']);
    installerAssert(
        $exit === 1 && (int) $database->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME LIKE 'kadupul_cdef_%'")->fetchColumn() === 9,
        'actual schema CLI refuses unsafe orphan before completing partially removed guard'
    );
    $database->exec('DELETE FROM graph_templates_item WHERE cdef_id=16777215');
    [$exit, $output, $errors] = installerRun($root, $environment, 'cli/upgrade_database.php', ['--install-cdef-reference-contract']);
    installerAssert($exit === 0 && $errors === '', 'actual schema CLI restores exact contract after deliberate fixture repair');
    echo "PASS actual current-version CLI installer probe complete\n";
} finally {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
    if ($created) {
        $database->exec("DROP DATABASE `$schema`");
    }
}
installerAssert(true, 'native current-version CLI and cleanup complete');
