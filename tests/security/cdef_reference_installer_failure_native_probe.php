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
    $database->exec("CREATE DATABASE `$schema`");
    $created = true;
    $database->exec("USE `$schema`");
    installerSeed($database, $root);
    $database->exec("INSERT INTO cdef_items (id,hash,cdef_id,sequence,type,value) VALUES(1000000,'" . str_repeat('f', 32) . "',16777215,1,1,'1')");
    [$exit, $output, $errors] = installerRun($root, $environment, 'cli/install_cacti.php', ['--install', '--accept-eula', '--automationmode=0']);
    installerAssert(
        $database->query('SELECT cacti FROM version')->fetchColumn() === 'new_install',
        'failed actual shared Installer leaves final version unrecorded'
    );
    installerAssert(
        str_contains($output, 'primary CDEF reference contract could not be installed'),
        'actual shared Installer surfaces contract failure'
    );
    installerAssert($exit !== 0, 'actual fresh CLI reports contract failure with nonzero exit');
} finally {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
    if ($created) {
        $database->exec("DROP DATABASE `$schema`");
    }
}
