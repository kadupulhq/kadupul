<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

test('the complete audit CLI preserves client arguments and stops on client failure', function ($schemaComplete, $markerPresent, $importExit, $mode, $invoked, $expectedExit, $message) {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/audit client space ' . bin2hex(random_bytes(8));
    foreach (['', '/cli', '/include', '/docs', '/bin'] as $suffix) {
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
    $bootstrap = '<?php ' . $helper
        . '$config = ["base_path" => dirname(__DIR__), "poller_id" => 1];'
        . 'define("CACTI_VERSION", "fixture"); define("COPYRIGHT_YEARS", "2026");'
        . 'putenv("CACTI_MYSQL_CLIENT=" . dirname(__DIR__) . "/bin/mysql");'
        . '$database_default = ' . var_export($database, true) . ';'
        . '$database_username = "fixture"; $database_password = "fixture";'
        . '$database_hostname = "fixture"; $database_port = "3306";'
        . '$database_ssl = false;'
        . 'function cacti_sizeof($value) { return is_countable($value) ? count($value) : 0; }'
        . 'function db_fetch_cell($sql) { return strpos($sql, "COUNT(*)") !== false ? 1 : "fixture"; }'
        . 'function db_execute($sql) { return true; }'
        . 'function db_table_exists($name) { return strpos($name, "audit_complete_") !== 0 || ' . var_export($markerPresent, true) . '; }'
        . 'function db_fetch_assoc($sql) { throw new LogicException("Forbidden scan after import failure"); }'
        . 'function cacti_escapeshellarg($value) { return escapeshellarg($value); }';
    $write($dir . '/include/cli_check.php', $bootstrap);
    $client = '#!' . PHP_BINARY . "\n<?php\n"
        . '$receipt = ["argv" => array_slice($argv, 1), "stdin" => stream_get_contents(STDIN), "password" => getenv("MYSQL_PWD")];'
        . 'file_put_contents(__DIR__ . "/../handoff.json", json_encode($receipt, JSON_THROW_ON_ERROR));'
        . 'if (' . $importExit . ' !== 0) fwrite(STDERR, "fixture client import failed\n");'
        . 'exit(' . $importExit . ');';
    $write($dir . '/bin/mysql', $client);
    chmod($dir . '/bin/mysql', 0700);
    try {
        $process = proc_open(
            [PHP_BINARY, $dir . '/cli/audit_database.php', $mode],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $dir
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
            expect($actual['argv'])->toBe(['--user=fixture', '--host=fixture', '--port=3306', '--database=' . $database]);
            expect($actual['password'])->toBe('fixture');
            expect($actual['stdin'])->toStartWith($schema)->toMatch('/CREATE TABLE `audit_complete_[0-9a-f]{16}`/')->toMatch('/INSERT INTO `audit_complete_[0-9a-f]{16}` VALUES \(1\);/');
        } else {
            expect(is_file($dir . '/handoff.json'))->toBeFalse();
        }
        if ($expectedExit !== 0) {
            expect($output)->not->toContain('Scanning Table:')->not->toContain('Audit was clean')
                ->not->toContain('Repair Completed')->not->toContain('Forbidden scan');
        }
    } finally {
        foreach (['/cli', '/include', '/docs', '/bin', ''] as $suffix) {
            foreach (glob($dir . $suffix . '/*') as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($dir . $suffix);
        }
    }
})->with([
    'literal database name and password environment' => [true, true, 0, '--create', true, 0, 'SUCCESS: Loaded the Audit Schema'],
    'missing completion footer refuses client startup' => [false, true, 0, '--report', false, 1, 'Audit Schema completion footer is missing'],
    'missing completion marker refuses schema publication' => [true, false, 0, '--report', true, 1, 'Audit Schema import did not reach its completion marker'],
    'failed import stops create' => [true, true, 1, '--create', true, 1, 'Audit Schema import failed'],
    'failed import stops report' => [true, true, 1, '--report', true, 1, 'Audit Schema import failed'],
    'failed import stops repair' => [true, true, 1, '--repair', true, 1, 'Audit Schema import failed'],
    'failed import stops alters' => [true, true, 1, '--alters', true, 1, 'Audit Schema import failed'],
]);
