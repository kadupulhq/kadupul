<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Run only against a disposable database started by analyze_database_cli.sh.
$port = getenv('ANALYZE_DB_PORT');
$password = getenv('ANALYZE_DB_PASSWORD');
$expectedBinlog = getenv('ANALYZE_DB_BINLOG') === 'on';
if (!$port || !$password) {
    throw new RuntimeException('Disposable database connection is required.');
}
$admin = new PDO('mysql:host=127.0.0.1;port=' . $port, 'root', $password, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
echo 'Native database version: ' . $admin->getAttribute(PDO::ATTR_SERVER_VERSION) . PHP_EOL;
$actualBinlog = $admin->query("SHOW GLOBAL VARIABLES LIKE 'log_bin'")->fetch(PDO::FETCH_ASSOC);
if ((strtolower($actualBinlog['Value']) === 'on') !== $expectedBinlog) {
    throw new RuntimeException('The native server binary-log mode does not match the requested contract.');
}
$root = dirname(__DIR__, 2);
$id = bin2hex(random_bytes(8));
$database = 'analyze_contract_' . $id;
$user = 'analyze_' . $id;
$fixturePassword = bin2hex(random_bytes(16));
$directory = sys_get_temp_dir() . '/analyze-cli-' . $id;
mkdir($directory, 0700);
mkdir($directory . '/cli', 0700);
mkdir($directory . '/include', 0700);
mkdir($directory . '/lib', 0700);
foreach (array('cli/analyze_database.php', 'lib/database.php') as $relative) {
    copy($root . '/' . $relative, $directory . '/' . $relative);
    if (hash_file('sha256', $root . '/' . $relative) !== hash_file('sha256', $directory . '/' . $relative)) {
        throw new RuntimeException('The native CLI fixture must execute unchanged production files.');
    }
}
$bootstrap = <<<'PHP'
<?php
$config = array('poller_id' => 1);
$database_hostname = '127.0.0.1';
$database_port = getenv('ANALYZE_DB_PORT');
$database_default = getenv('ANALYZE_FIXTURE_DATABASE');
$pdo = new PDO('mysql:host=127.0.0.1;port=' . $database_port . ';dbname=' . $database_default,
    getenv('ANALYZE_FIXTURE_USER'), getenv('ANALYZE_FIXTURE_PASSWORD'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT));
$database_sessions = array("$database_hostname:$database_port:$database_default" => $pdo);
$database_total_queries = 0;
define('POLLER_VERBOSITY_DEBUG', 5);
require __DIR__ . '/../lib/database.php';
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_count($value) { return count($value); }
function clean_up_lines($value) { return $value; }
function cacti_debug_backtrace(...$args) {}
function cacti_log($message, ...$args) { file_put_contents(dirname(__DIR__) . '/events.log', $message . "\n", FILE_APPEND); }
PHP;
file_put_contents($directory . '/include/cli_check.php', $bootstrap);
putenv('ANALYZE_FIXTURE_DATABASE=' . $database);
putenv('ANALYZE_FIXTURE_USER=' . $user);
putenv('ANALYZE_FIXTURE_PASSWORD=' . $fixturePassword);
$assert = function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$run = function () use ($directory, $assert): array {
    @unlink($directory . '/events.log');
    $stderr = tmpfile();
    $assert(is_resource($stderr), 'Cannot create owned stderr capture.');
    try {
        $process = proc_open(array(PHP_BINARY, $directory . '/cli/analyze_database.php', '--local'),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => $stderr), $pipes);
        $assert(is_resource($process), 'Cannot run production CLI.');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);
        rewind($stderr);
        $assert(stream_get_contents($stderr) === '', 'Production CLI emitted unexpected stderr.');
        return array($status, $output, is_file($directory . '/events.log') ? file_get_contents($directory . '/events.log') : '');
    } finally {
        fclose($stderr);
    }
};
try {
    $admin->exec('CREATE DATABASE `' . $database . '`');
    $admin->exec('CREATE USER ' . $admin->quote($user) . "@'%' IDENTIFIED BY " . $admin->quote($fixturePassword));
    $admin->exec('GRANT SELECT, INSERT ON `' . $database . '`.* TO ' . $admin->quote($user) . "@'%'");
    $admin->exec('CREATE TABLE `' . $database . '`.`a_denied` (id INT PRIMARY KEY) ENGINE=InnoDB');
    $admin->exec('CREATE TABLE `' . $database . '`.`z_allowed``tick` (id INT PRIMARY KEY) ENGINE=InnoDB');
    $admin->exec('INSERT INTO `' . $database . '`.`a_denied` VALUES (1)');
    $admin->exec('INSERT INTO `' . $database . '`.`z_allowed``tick` VALUES (2)');
    $version = $admin->getAttribute(PDO::ATTR_SERVER_VERSION);
    $statusQuery = stripos($version, 'MariaDB') === false && version_compare($version, '8.4', '>=') ? 'SHOW BINARY LOG STATUS' : 'SHOW MASTER STATUS';
    $before = $expectedBinlog ? $admin->query($statusQuery)->fetch(PDO::FETCH_ASSOC) : false;
    list($status, $output, $log) = $run();
    $after = $expectedBinlog ? $admin->query($statusQuery)->fetch(PDO::FETCH_ASSOC) : false;
    $assert($status === 0 && substr_count($output, ' Successful') === 2 && strpos($output, ' Failed') === false,
        'Native analysis must successfully process both tables, including the quoted identifier.');
    $assert(strpos($log, 'Analyzing Cacti Tables complete.') !== false, 'Successful completion must be logged.');
    $assert(substr_count($output, 'without writing to the binlog') === ($expectedBinlog ? 2 : 0), 'CLI selected the wrong native log-bin branch.');
    $assert(!$expectedBinlog || $before === $after, 'ANALYZE NO_WRITE_TO_BINLOG changed the binary-log position.');
    echo 'PASS: native successful analysis, quoted identifiers and binary-log branch' . PHP_EOL;

    // ANALYZE requires SELECT and INSERT. The first table fails while its sibling remains permitted.
    $admin->exec('REVOKE INSERT ON `' . $database . '`.* FROM ' . $admin->quote($user) . "@'%'");
    $admin->exec('GRANT INSERT ON `' . $database . '`.`z_allowed``tick` TO ' . $admin->quote($user) . "@'%'");
    list($status, $output, $log) = $run();
    $assert($status === 1 && substr_count($output, ' Failed') === 1 && substr_count($output, ' Successful') === 1,
        'A native per-table SQL permission failure must exit nonzero and continue to the permitted table.');
    $assert(strpos($log, 'command denied') !== false && strpos($log, 'completed with errors') !== false,
        'Native permission error and failed completion must be logged.');
    $assert((int) $admin->query('SELECT COUNT(*) FROM `' . $database . '`.`a_denied`')->fetchColumn() === 1
        && (int) $admin->query('SELECT COUNT(*) FROM `' . $database . '`.`z_allowed``tick`')->fetchColumn() === 1, 'Analysis changed table data.');
    echo 'PASS: native per-table SQL failure, continued sibling and nonzero exit' . PHP_EOL;

    $admin->exec('DROP TABLE `' . $database . '`.`a_denied`, `' . $database . '`.`z_allowed``tick`');
    list($status, $output, $log) = $run();
    $assert($status === 1 && strpos($output, ' Successful') === false && strpos($log, 'complete.') === false,
        'An empty schema must fail without claiming a successful analysis.');
    echo 'PASS: empty native schema fails closed' . PHP_EOL;
} finally {
    $admin->exec('DROP USER IF EXISTS ' . $admin->quote($user) . "@'%'");
    $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    $paths = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($paths as $path) {
        $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
    }
    rmdir($directory);
    foreach (array('ANALYZE_FIXTURE_DATABASE', 'ANALYZE_FIXTURE_USER', 'ANALYZE_FIXTURE_PASSWORD') as $name) {
        putenv($name);
    }
}
