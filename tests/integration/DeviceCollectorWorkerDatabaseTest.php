<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

$applicationLoader = require dirname(__DIR__, 2) . '/include/vendor/autoload.php';
$applicationLoader->unregister();
$applicationLoader->register(false);

use Kadupul\Inventory\Domain\DeviceMaintenanceRequest;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceAssociationRecords;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceMaintenanceRecords;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class DeviceCollectorWorkerDatabaseTest extends TestCase
{
    private string $prefix;
    private PDO $database;

    protected function setUp(): void
    {
        if (!getenv('KADUPUL_TEST_MYSQL_DSN')) {
            self::markTestSkipped('KADUPUL_TEST_MYSQL_DSN is required for database contracts');
        }
        if (!class_exists(DeviceWorkerProbeDatabase::class, false)) {
            // Require the PDO adapter without running the worker bootstrap.
            $savedRoot = getenv('KADUPUL_TEST_PROJECT_ROOT');
            putenv('KADUPUL_TEST_PROJECT_ROOT=' . dirname(__DIR__, 2));
            putenv('KADUPUL_TEST_PROBE_PARENT=1');
            try {
                require __DIR__ . '/../Fixtures/device-worker-probe-bootstrap.php';
            } finally {
                putenv('KADUPUL_TEST_PROBE_PARENT');
                $savedRoot === false ? putenv('KADUPUL_TEST_PROJECT_ROOT') : putenv('KADUPUL_TEST_PROJECT_ROOT=' . $savedRoot);
            }
        }
        $this->prefix = 'collector_probe_' . bin2hex(random_bytes(8)) . '_';
        $this->database = new DeviceWorkerProbeDatabase($this->prefix);
        $schema = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        foreach (DeviceWorkerProbeDatabase::TABLES as $table) {
            if (!preg_match('/CREATE TABLE `?' . preg_quote($table, '/') . '`? \(.*?;\n/s', $schema, $match)) {
                throw new RuntimeException('Missing install schema table');
            }
            $this->database->exec($this->database->sql($match[0]));
        }
        foreach ([
            "INSERT INTO settings (name,value) VALUES ('auth_method','1'),('guest_user','0'),('graph_auth_method','1'),('selective_device_debug','')",
            "INSERT INTO user_auth (id,username,enabled,locked,policy_graphs,policy_hosts,policy_graph_templates) VALUES (7,'fixture','on','',1,1,1)",
            'INSERT INTO user_auth_realm (user_id,realm_id) VALUES (7,8),(7,3)',
            "INSERT INTO host (id,description,hostname,site_id,poller_id,status,snmp_version,disabled,deleted) VALUES (11,'fixture','192.0.2.1',0,1,3,2,'','')",
            "INSERT INTO snmp_query (id,name) VALUES (9,'fixture')",
        ] as $sql) {
            $this->database->exec($this->database->sql($sql));
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->database)) {
            return;
        }
        foreach (array_reverse(DeviceWorkerProbeDatabase::TABLES) as $table) {
            $this->database->exec('DROP TABLE IF EXISTS ' . $this->prefix . $table);
        }
    }

    public function testHeartbeatUpdatesProceedDuringBothProductionWorkers(): void
    {
        foreach (['associations', 'maintenance'] as $kind) {
            $this->probe($kind, 'UPDATE poller SET last_status=NOW(), total_polls=total_polls+1 WHERE id=1', true);
        }
    }

    public function testCollectorChangesDuringSlowWorkRejectCommitAndRollBack(): void
    {
        foreach (['associations', 'maintenance'] as $kind) {
            foreach (["UPDATE poller SET dbhost='changed.invalid' WHERE id=1", "UPDATE poller SET disabled='on' WHERE id=1", 'DELETE FROM poller WHERE id=1'] as $sql) {
                $this->probe($kind, $sql, false);
            }
        }
    }

    public function testMalformedAssociationKindsAndOperationsAreInvalidBeforeWrites(): void
    {
        foreach (['kind', 'operation'] as $field) {
            foreach ([[], false, 5, null] as $value) {
                $this->probe('associations', '', false, [$field => $value]);
            }
        }
    }

    public function testFailedQueryRefreshRollsBackBothAssociationOperations(): void
    {
        foreach (['add', 'change'] as $operation) {
            $this->probe('associations', 'UPDATE poller SET total_polls=total_polls+1 WHERE id=1', false, null, true, $operation);
        }
    }

    private function probe(string $kind, string $concurrentChange, bool $success, ?array $invalidFields = null, bool $queryFailure = false, string $operation = 'add'): void
    {
        $this->database->exec($this->database->sql('DELETE FROM host_snmp_query; DELETE FROM poller_reindex; DELETE FROM poller'));
        $this->database->exec($this->database->sql("INSERT INTO poller (id,name,last_status,dbhost) VALUES (1,'fixture',NOW(),'collector.invalid')"));
        if ($kind === 'maintenance' || $operation === 'change') {
            $this->database->exec($this->database->sql('INSERT INTO host_snmp_query (host_id,snmp_query_id,reindex_method) VALUES (11,9,0)'));
        }
        $row = $this->database->query('SELECT * FROM host WHERE id=11')->fetch(PDO::FETCH_ASSOC);
        if ($kind === 'associations') {
            $state = (new DeviceAssociationRecords())->snapshot($this->database, $row, 'query');
            $command = ['actor' => 7, 'id' => 11, 'kind' => 'query', 'operation' => $operation, 'target' => 9, 'revision' => $state->revision(), 'reindex' => 2];
        } else {
            $state = (new DeviceMaintenanceRecords())->snapshot($this->database, $row);
            $command = ['actor' => 7, 'id' => 11, 'operation' => 'reindex', 'query' => 0, 'revision' => $state->revision()];
        }
        if ($invalidFields !== null) {
            $command = array_replace($command, $invalidFields);
        }
        $directory = sys_get_temp_dir() . '/collector-worker-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        mkdir($directory . '/lib', 0700);
        $root = dirname(__DIR__, 2);
        $sourceDirectory = getenv('KADUPUL_TEST_WORKER_SOURCE_DIR') ?: $root . '/bin';
        $worker = '/legacy-device-' . $kind . '.php';
        copy($sourceDirectory . $worker, $directory . '/bin' . $worker);
        file_put_contents($directory . '/bin/legacy-assignment-bootstrap.php', '<?php require ' . var_export($root . '/tests/Fixtures/device-worker-probe-bootstrap.php', true) . ';');
        file_put_contents($directory . '/lib/api_automation.php', '<?php');
        file_put_contents($directory . '/lib/sort.php', '<?php');
        $process = new Process([PHP_BINARY, $directory . '/bin' . $worker], $directory, [
            'KADUPUL_TEST_PROJECT_ROOT' => $root,
            'KADUPUL_TEST_PROBE_PARENT' => false,
            'KADUPUL_TEST_PROBE_PREFIX' => $this->prefix,
            'KADUPUL_TEST_PROBE_READY' => $directory . '/ready',
            'KADUPUL_TEST_PROBE_RELEASE' => $directory . '/release',
        ]);
        $process->setInput(json_encode($command, JSON_THROW_ON_ERROR));
        $process->setTimeout(20);
        try {
            $process->start();
            if ($invalidFields !== null) {
                $process->wait();
                self::assertSame('', $process->getErrorOutput());
                self::assertSame(1, $process->getExitCode());
                self::assertStringContainsString('"status":"invalid"', $process->getOutput());
                self::assertFileDoesNotExist($directory . '/ready');
                self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM host_snmp_query')->fetchColumn());
                self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM poller_reindex')->fetchColumn());
                return;
            }
            $deadline = microtime(true) + 10;
            while (!is_file($directory . '/ready') && $process->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertFileExists($directory . '/ready', $process->getOutput() . $process->getErrorOutput());
            $started = microtime(true);
            $this->database->exec($this->database->sql($concurrentChange));
            self::assertLessThan(1.0, microtime(true) - $started, 'Collector update blocked during network phase');
            file_put_contents($directory . '/release', $queryFailure ? 'fail' : 'release');
            $process->wait();
            self::assertSame('', $process->getErrorOutput());
            self::assertSame($success ? 0 : 1, $process->getExitCode());
            self::assertStringContainsString('"status":"' . ($success ? 'ok' : 'failed') . '"', $process->getOutput());
            self::assertSame($success ? 1 : 0, (int) $this->database->query('SELECT COUNT(*) FROM poller_reindex WHERE host_id=11')->fetchColumn());
            self::assertSame($kind === 'maintenance' || $operation === 'change' || $success ? 1 : 0, (int) $this->database->query('SELECT COUNT(*) FROM host_snmp_query WHERE host_id=11')->fetchColumn());
            if ($operation === 'change' && !$success) {
                self::assertSame(0, (int) $this->database->query('SELECT reindex_method FROM host_snmp_query WHERE host_id=11')->fetchColumn());
            }
        } finally {
            file_put_contents($directory . '/release', 'release');
            if ($process->isRunning()) {
                $process->wait();
            }
            foreach (['ready', 'release', 'bin' . $worker, 'bin/legacy-assignment-bootstrap.php', 'lib/api_automation.php', 'lib/sort.php'] as $file) {
                if (is_file($directory . '/' . $file)) {
                    unlink($directory . '/' . $file);
                }
            }
            rmdir($directory . '/bin');
            rmdir($directory . '/lib');
            rmdir($directory);
        }
    }
}
