<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\AssetManagement;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

final class AssetRegistryDatabaseTest extends AssetRegistryTest
{
    public function testWorkerUsesTheRealConsoleCommandAndRechecksTheOperator(): void
    {
        $root = dirname(__DIR__, 3);
        $sql = file_get_contents($root . '/cacti.sql');
        foreach (['settings', 'user_auth', 'user_auth_realm'] as $table) {
            $this->db->executeStatement('DROP TABLE IF EXISTS ' . $table);
            $this->assertSame(1, preg_match('/^CREATE TABLE ' . $table . ' \(.*?^\) [^;\n]*;/ms', $sql, $match));
            $this->db->executeStatement($match[0]);
        }
        $this->db->insert('settings', ['name' => 'path_php_binary', 'value' => PHP_BINARY]);
        $this->db->insert('user_auth', ['id' => 1, 'username' => 'operator', 'enabled' => 'on', 'must_change_password' => '']);
        foreach ([8, 15] as $realm) {
            $this->db->insert('user_auth_realm', ['user_id' => 1, 'realm_id' => $realm]);
        }
        $directory = sys_get_temp_dir() . '/assets-worker-' . bin2hex(random_bytes(8));
        $files = new \Symfony\Component\Filesystem\Filesystem();
        $files->mkdir([$directory . '/bin', $directory . '/include', $directory . '/log'], 0700);
        $files->copy(dirname(__DIR__) . '/Fixtures/asset_console.php', $directory . '/bin/console');
        $files->dumpFile($directory . '/include/config.php', '<?php $poller_id = 1;');
        $oldProject = getenv('KADUPUL_ASSET_PROJECT');
        $oldProjectEnv = $_ENV['KADUPUL_ASSET_PROJECT'] ?? null;
        putenv('KADUPUL_ASSET_PROJECT=' . $root);
        $_ENV['KADUPUL_ASSET_PROJECT'] = $root;
        try {
            $console = $this->createMock(\Kadupul\IdentityAccess\Contract\ConsoleAccess::class);
            $console->method('consoleActor')->willReturn(new \Kadupul\IdentityAccess\Contract\Actor(1, 'operator'));
            $permission = $this->createMock(\Kadupul\IdentityAccess\Contract\AssetManagementPermission::class);
            $permission->method('mayManageAssets')->willReturn(true);
            $topology = $this->createMock(\Kadupul\IdentityAccess\Contract\CollectorTopology::class);
            $access = new \Kadupul\AssetManagement\Infrastructure\Symfony\WebAssetAccess(true, $console, $permission, $topology);
            $worker = new \Kadupul\AssetManagement\Infrastructure\Symfony\AssetWorker($access, $this->db, $directory);
            $probe = new \Symfony\Component\Process\Process([PHP_BINARY, $directory . '/bin/console', 'assets:register', '--operator', 'operator'], $directory);
            $probe->run();
            $this->assertSame(0, $probe->getExitCode(), $probe->getOutput() . $probe->getErrorOutput());
            $command = ['action' => 'create', 'request_id' => str_repeat('e', 32), 'revision' => 1, 'fields' => ['name' => 'Worker-created switch']];
            $result = $worker->apply($command);
            $this->assertSame(2, $result['revision']);
            $this->assertSame($result, $worker->apply($command));
            $this->assertCount(1, $this->store->snapshot()['assets']);
            $this->assertSame('operator', $this->store->history(PHP_INT_MAX, 1)[0]['actor']);
            $this->assertStringContainsString('asset.command', file_get_contents($directory . '/log/kadupul-audit.jsonl'));
            $this->db->update('user_auth', ['locked' => 'on'], ['id' => 1]);
            $this->rejected('denied', fn() => $worker->apply($command));
            $this->db->update('user_auth', ['locked' => ''], ['id' => 1]);
            $files->dumpFile($directory . '/include/config.php', '<?php $poller_id = 2;');
            $this->rejected('denied', fn() => $worker->apply($command));
        } finally {
            putenv($oldProject === false ? 'KADUPUL_ASSET_PROJECT' : 'KADUPUL_ASSET_PROJECT=' . $oldProject);
            if ($oldProjectEnv === null) {
                unset($_ENV['KADUPUL_ASSET_PROJECT']);
            } else {
                $_ENV['KADUPUL_ASSET_PROJECT'] = $oldProjectEnv;
            }
            $files->remove($directory);
            foreach (['settings', 'user_auth', 'user_auth_realm'] as $table) {
                $this->db->executeStatement('DROP TABLE ' . $table);
            }
        }
    }

    public function testLostCommitAcknowledgementReturnsTheOriginalReceiptOnRetry(): void
    {
        $params = (new \Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse(getenv('KADUPUL_ASSET_TEST_DSN'));
        $params['password'] = getenv('KADUPUL_ASSET_TEST_PASSWORD') ?: ($params['password'] ?? '');
        $params['wrapperClass'] = LostAssetCommitAcknowledgement::class;
        $connection = DriverManager::getConnection($params);
        $registry = new \Kadupul\AssetManagement\Application\AssetRegistry($this->access, new \Kadupul\AssetManagement\Infrastructure\Persistence\DoctrineAssetStore($connection), new \Kadupul\AssetManagement\Infrastructure\Persistence\MonitoringSnapshot($connection), $this->createMock(\Kadupul\IdentityAccess\Contract\AuditTrail::class));
        $command = ['action' => 'create', 'request_id' => str_repeat('d', 32), 'revision' => 1, 'fields' => ['name' => 'Committed before acknowledgement']];
        try {
            $registry->apply($command);
            $this->fail('An unacknowledged commit must not report success.');
        } catch (\Kadupul\AssetManagement\Domain\AssetError $error) {
            $this->assertSame('uncertain', $error->reason);
        } finally {
            $connection->close();
        }
        $result = $this->registry->apply($command);
        $this->assertSame(2, $result['revision']);
        $this->assertSame($result, $this->registry->apply($command));
        $this->assertCount(1, $this->store->snapshot()['assets']);
        $this->assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM asset_event'));
    }

    public function testConcurrentWriterWaitsForTheRegisterAndRetriesAfterRollback(): void
    {
        $params = (new \Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse(getenv('KADUPUL_ASSET_TEST_DSN'));
        $params['password'] = getenv('KADUPUL_ASSET_TEST_PASSWORD') ?: ($params['password'] ?? '');
        $other = DriverManager::getConnection($params);
        $other->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        $second = new \Kadupul\AssetManagement\Application\AssetRegistry($this->access, new \Kadupul\AssetManagement\Infrastructure\Persistence\DoctrineAssetStore($other), new \Kadupul\AssetManagement\Infrastructure\Persistence\MonitoringSnapshot($other), $this->createMock(\Kadupul\IdentityAccess\Contract\AuditTrail::class));
        $command = ['action' => 'create', 'request_id' => str_repeat('c', 32), 'revision' => 1, 'fields' => ['name' => 'Concurrent asset']];
        $this->db->beginTransaction();
        $this->db->fetchOne('SELECT revision FROM asset_registry WHERE id=1 FOR UPDATE');
        try {
            $second->apply($command);
            $this->fail('The second writer must not pass the register lock.');
        } catch (\Doctrine\DBAL\Exception\LockWaitTimeoutException) {
            $this->assertFalse($other->isTransactionActive());
        } finally {
            $this->db->rollBack();
        }
        $this->assertSame(2, $second->apply($command)['revision']);
        $this->assertSame(2, $second->apply($command)['revision']);
        $this->assertCount(1, $this->store->snapshot()['assets']);
        $other->close();
    }

    public function testLateJournalFailureRollsBackRecordAndRevision(): void
    {
        $this->db->executeStatement("CREATE TRIGGER asset_fail_journal BEFORE INSERT ON asset_event FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected journal failure'");
        $before = $this->store->snapshot();
        try {
            $this->asset();
            $this->fail('The late journal failure must abort the whole mutation.');
        } catch (\Doctrine\DBAL\Exception) {
            $this->assertSame($before, $this->store->snapshot());
        }
        $this->db->executeStatement('DROP TRIGGER asset_fail_journal');
        $this->asset();
        $this->assertCount(1, $this->store->snapshot()['assets']);
    }

    protected function connection(): Connection
    {
        $dsn = getenv('KADUPUL_ASSET_TEST_DSN');
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('Set KADUPUL_ASSET_TEST_DSN to a disposable database ending in _assets_test.');
        }
        $params = (new \Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse($dsn);
        $params['password'] = getenv('KADUPUL_ASSET_TEST_PASSWORD') ?: ($params['password'] ?? '');
        if (!str_ends_with($params['dbname'] ?? '', '_assets_test')) {
            throw new \RuntimeException('Only an explicitly task-owned asset test database is allowed.');
        }
        $db = DriverManager::getConnection($params, $this->queryConfiguration());
        foreach (['asset_registry', 'asset_record', 'asset_event', 'asset_observation', 'host'] as $table) {
            $db->executeStatement('DROP TABLE IF EXISTS ' . $table);
        }
        $sql = file_get_contents(dirname(__DIR__, 3) . '/install/asset-management.sql');
        $this->assertIsString($sql);
        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) !== '') {
                $db->executeStatement($statement);
            }
        }
        return $db;
    }
}

final class LostAssetCommitAcknowledgement extends Connection
{
    public function commit(): void
    {
        parent::commit();
        throw new \RuntimeException('Injected loss of the successful commit acknowledgement.');
    }
}
