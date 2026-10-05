<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
require_once dirname(__DIR__, 3) . '/Helpers/AuditClientNativeEvidence.php';

test('the complete audit CLI preserves client arguments and stops on client failure', function (bool $schemaComplete, bool $markerPresent, int $importExit, string $mode, bool $invoked, int $expectedExit, string $message, string $clientVersion = 'mysql Ver 8.0.36 MySQL', int $versionExit = 0, bool $databaseSsl = false, array $tlsArguments = ['--ssl-mode=DISABLED'], int $versionDelay = 0): void {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/audit client space ' . bin2hex(random_bytes(8));
    foreach (['', '/cli', '/include', '/docs', '/bin', '/tmp'] as $suffix) {
        if (!mkdir($dir . $suffix, 0700)) {
            throw new RuntimeException('Unable to create owned audit fixture');
        }
    }
    $write = static function (string $path, string $bytes): void {
        if (file_put_contents($path, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Unable to write complete audit fixture');
        }
    };
    $database = 'fixture; echo injected > ' . escapeshellarg($dir . '/injected') . '; #';
    $schema = "-- complete fixture baseline\nSELECT 1;\n" . ($schemaComplete ? "-- Dump completed on 2026-10-02 00:00:00\n" : "");
    if (!copy($root . '/cli/audit_database.php', $dir . '/cli/audit_database.php')) {
        throw new RuntimeException('Unable to copy complete audit CLI');
    }
    $write($dir . '/docs/audit_schema.sql', $schema);
    $source = file_get_contents($root . '/lib/database.php');
    if ($source === false) {
        throw new RuntimeException('Unable to read actual database client helper');
    }
    $helper = test_php_function_source($source, 'db_client_ssl_option');
    $bootstrap = '<?php '
        . 'require_once ' . var_export($root . '/include/vendor/autoload.php', true) . ';' . $helper
        . '$config = ["base_path" => dirname(__DIR__), "poller_id" => 1];'
        . 'define("CACTI_VERSION", "fixture"); define("COPYRIGHT_YEARS", "2026");'
        . 'putenv("CACTI_MYSQL_CLIENT=" . dirname(__DIR__) . "/bin/mysql");'
        . '$database_default = ' . var_export($database, true) . ';'
        . '$database_username = "fixture"; $database_password = "fixture";'
        . '$database_hostname = "fixture"; $database_port = "3306";'
        . '$database_ssl = ' . var_export($databaseSsl, true) . ';'
        . 'function cacti_sizeof($value) { return is_countable($value) ? count($value) : 0; }'
        . 'function db_fetch_cell($sql) { return strpos($sql, "COUNT(*)") !== false ? 1 : "fixture"; }'
        . 'function db_execute($sql) { file_put_contents(dirname(__DIR__) . "/db-mutations", $sql . "\\n", FILE_APPEND); return true; }'
        . 'function db_table_exists($name) { return strpos($name, "audit_complete_") !== 0 || ' . var_export($markerPresent, true) . '; }'
        . 'function db_fetch_assoc($sql) { throw new LogicException("Forbidden scan after import failure"); }'
        . 'function cacti_escapeshellarg($value) { return escapeshellarg($value); }';
    $write($dir . '/include/cli_check.php', $bootstrap);
    $client = '#!' . PHP_BINARY . "\n<?php\n"
        . 'if (array_slice($argv, 1) === ["--version"]) { file_put_contents(__DIR__ . "/../version-invoked", "yes"); file_put_contents(__DIR__ . "/../version-pid", (string) getmypid()); echo ' . var_export($clientVersion, true) . '; usleep(' . $versionDelay . ' * 1000000); file_put_contents(__DIR__ . "/../version-finished", "yes"); exit(' . $versionExit . '); }'
        . '$receipt = ["argv" => array_slice($argv, 1), "stdin" => stream_get_contents(STDIN), "password" => getenv("MYSQL_PWD")];'
        . 'file_put_contents(__DIR__ . "/../handoff.json", json_encode($receipt, JSON_THROW_ON_ERROR));'
        . 'if (' . $importExit . ' !== 0) fwrite(STDERR, "fixture client import failed\n");'
        . 'exit(' . $importExit . ');';
    $write($dir . '/bin/mysql', $client);
    chmod($dir . '/bin/mysql', 0700);
    try {
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        $scenario = json_encode(func_get_args(), JSON_THROW_ON_ERROR);
        $command = [PHP_BINARY];
        if ($coverage !== null) {
            $command = [...$command, '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/vendor/~',
                '-d', 'auto_prepend_file=' . $root . '/' . AuditClientNativeEvidence::PRODUCER];
        }
        $command = [...$command, $dir . '/cli/audit_database.php', $mode];
        $started = microtime(true);
        $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $dir,
            array_merge(getenv(), ['TMPDIR' => $dir . '/tmp', 'AUDIT_CLIENT_COVERAGE_DIRECTORY' => $dir, 'AUDIT_CLIENT_COVERAGE_SCENARIO' => $scenario])
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start complete audit CLI');
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== $expectedExit) {
            throw new RuntimeException("Exit " . $status . ": " . $output);
        }
        expect($output)->toContain($message);
        expect(is_file($dir . '/injected'))->toBeFalse();
        if ($invoked) {
            expect(is_file($dir . '/handoff.json'))->toBeTrue();
            $actual = json_decode(file_get_contents($dir . '/handoff.json'), true, flags: JSON_THROW_ON_ERROR);
            expect($actual['argv'])->toBe(['--user=fixture', '--host=fixture', '--port=3306', '--database=' . $database, ...$tlsArguments]);
            expect(is_file($dir . '/version-invoked'))->toBeTrue();
            expect($actual['password'])->toBe('fixture');
            expect($actual['stdin'])->toStartWith($schema)->toMatch('/CREATE TABLE `audit_complete_[0-9a-f]{16}`/')->toMatch('/INSERT INTO `audit_complete_[0-9a-f]{16}` VALUES \(1\);/');
        } else {
            expect(is_file($dir . '/handoff.json'))->toBeFalse();
        }
        if ($message === 'Unable to determine a safe TLS option') {
            expect(is_file($dir . '/version-invoked'))->toBeTrue();
            expect(is_file($dir . '/db-mutations'))->toBeFalse();
        }
        expect(glob($dir . '/tmp/kadupul-audit-*'))->toBe([]);
        if ($versionDelay > 0) {
            expect(microtime(true) - $started)->toBeLessThan(7.5);
            expect(is_file($dir . '/version-finished'))->toBeFalse();
            if (function_exists('posix_kill')) {
                expect(posix_kill((int) file_get_contents($dir . '/version-pid'), 0))->toBeFalse();
            }
        }
        if ($expectedExit !== 0) {
            expect($output)->not->toContain('Scanning Table:')->not->toContain('Audit was clean')
                ->not->toContain('Repair Completed')->not->toContain('Forbidden scan');
        }
        AuditClientNativeEvidence::verifyCopies($root, $dir);
        if ($coverage !== null) {
            $arguments = [$dir . '/audit-client.coverage', $root, AuditClientNativeEvidence::PRODUCER,
                $scenario, AuditClientNativeEvidence::SOURCES, AuditClientNativeEvidence::MARKERS, AuditClientNativeEvidence::HITS];
            $measured = NativeChildCoverageEvidence::load(...$arguments);
            expect(NativeChildCoverageEvidence::verifyRejections(...[...$arguments, 'cli/audit_database.php']))
                ->toBe(count(AuditClientNativeEvidence::SOURCES) + count(AuditClientNativeEvidence::MARKERS) + 10);
            $original = file_get_contents($dir . '/cli/audit_database.php');
            foreach ([null, $original . "\n// altered owned copy\n"] as $changed) {
                try {
                    $changed === null ? unlink($dir . '/cli/audit_database.php') : $write($dir . '/cli/audit_database.php', $changed);
                    expect(fn() => AuditClientNativeEvidence::verifyCopies($root, $dir))->toThrow(RuntimeException::class, 'copy differs');
                } finally {
                    $write($dir . '/cli/audit_database.php', $original);
                }
            }
            $coverage->merge($measured);
        }
    } finally {
        foreach (['/cli', '/include', '/docs', '/bin', '/tmp', ''] as $suffix) {
            foreach (glob($dir . $suffix . '/*') as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($dir . $suffix);
        }
    }
})->with([
    'sleeping client version refuses before import or DDL' => [true, true, 0, '--create', false, 1, 'Unable to determine a safe TLS option', 'mysql Ver 8.0.36 MySQL', 0, false, ['--ssl-mode=DISABLED'], 8],
    'MariaDB TLS disabled handoff' => [true, true, 0, '--create', true, 0, 'SUCCESS: Loaded the Audit Schema', 'mariadb Ver 15.1 Distrib 10.11.8-MariaDB', 0, false, ['--skip-ssl']],
    'configured TLS keeps default client options' => [true, true, 0, '--create', true, 0, 'SUCCESS: Loaded the Audit Schema', 'mysql Ver 8.0.36 MySQL', 0, true, []],
    'configured TLS preserves unknown-client helper policy' => [true, true, 0, '--create', true, 0, 'SUCCESS: Loaded the Audit Schema', 'unrecognized client', 0, true, []],
    'unknown client refuses before import or DDL' => [true, true, 0, '--create', false, 1, 'Unable to determine a safe TLS option', 'unrecognized client', 0],
    'failed version refuses before import or DDL' => [true, true, 0, '--repair', false, 1, 'Unable to determine a safe TLS option', 'mysql Ver 8.0.36 MySQL', 1],
    'literal database name and password environment' => [true, true, 0, '--create', true, 0, 'SUCCESS: Loaded the Audit Schema'],
    'missing completion footer refuses client startup' => [false, true, 0, '--report', false, 1, 'Audit Schema completion footer is missing'],
    'missing completion marker refuses schema publication' => [true, false, 0, '--report', true, 1, 'Audit Schema import did not reach its completion marker'],
    'failed import stops create' => [true, true, 1, '--create', true, 1, 'Audit Schema import failed'],
    'failed import stops report' => [true, true, 1, '--report', true, 1, 'Audit Schema import failed'],
    'failed import stops repair' => [true, true, 1, '--repair', true, 1, 'Audit Schema import failed'],
    'failed import stops alters' => [true, true, 1, '--alters', true, 1, 'Audit Schema import failed'],
]);
