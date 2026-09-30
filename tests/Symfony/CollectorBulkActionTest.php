<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\CollectorAdministration\Application\Command\ExecuteCollectorBulkAction;
use Kadupul\CollectorAdministration\Application\Command\PrepareCollectorBulkAction;
use Kadupul\CollectorAdministration\Application\Port\CollectorBulkOperations;
use Kadupul\CollectorAdministration\Application\Query\CollectorAccessDenied;
use Kadupul\CollectorAdministration\Domain\CollectorBulkAction;
use Kadupul\CollectorAdministration\Domain\CollectorSelection;
use Kadupul\CollectorAdministration\Infrastructure\Persistence\LegacyCollectorBulkOperations;
use Kadupul\CollectorAdministration\Infrastructure\Persistence\PdoCollectorOperatorAuthorization;
use Kadupul\CollectorAdministration\Infrastructure\Persistence\PdoCollectorBulkMutation;
use Kadupul\CollectorAdministration\Infrastructure\Persistence\PdoCollectorFullSynchronizer;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CollectorBulkActionTest extends TestCase
{
    public function testSelectionIsCanonicalAndRejectsDuplicatesAndMalformedIds(): void
    {
        self::assertSame([2, 9], (new CollectorSelection(['9', 2]))->ids);
        foreach ([[], [0], ['01'], ['1x'], [1, 1], [2147483648], [1 => 1, 2 => 2]] as $ids) {
            try {
                new CollectorSelection($ids);
                self::fail('Expected selection to be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testPrepareChecksRealmThreeAccessBeforeReadingAndProtectsPrimary(): void
    {
        $actor = new Actor(42, 'operator');
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn($actor);
        $access->method('canManageDevices')->willReturn(false);
        $operations = $this->createMock(CollectorBulkOperations::class);
        $operations->expects(self::never())->method('find');
        try {
            (new PrepareCollectorBulkAction($access, $operations))(CollectorBulkAction::Delete, new CollectorSelection([2]));
            self::fail('Expected access to be denied.');
        } catch (CollectorAccessDenied $error) {
            self::assertFalse($error->unauthenticated);
        }

        $authorized = $this->createMock(ConsoleAccess::class);
        $authorized->method('consoleActor')->willReturn($actor);
        $authorized->method('canManageDevices')->willReturn(true);
        try {
            (new PrepareCollectorBulkAction($authorized, $operations))(CollectorBulkAction::Delete, new CollectorSelection([1]));
            self::fail('Expected the primary collector to be protected.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    public function testBulkMutationsPreserveDeleteReassignmentAndStatisticsValues(): void
    {
        $database = $this->database();
        $this->seed($database);
        $mutation = new PdoCollectorBulkMutation();
        $mutation->apply($database, 42, CollectorBulkAction::ClearStatistics, new CollectorSelection([2]), static fn() => null);
        self::assertSame(0.0, (float) $database->query('SELECT total_time FROM poller WHERE id = 2')->fetchColumn());
        self::assertSame(9999999.0, (float) $database->query('SELECT min_time FROM poller WHERE id = 2')->fetchColumn());

        $result = $mutation->apply($database, 42, CollectorBulkAction::Delete, new CollectorSelection([2]), static fn() => null);
        self::assertSame(['successful' => [2], 'failed' => []], $result);
        self::assertSame(0, (int) $database->query('SELECT COUNT(*) FROM poller WHERE id = 2')->fetchColumn());
        self::assertSame(1, (int) $database->query("SELECT poller_id FROM host WHERE id = 5 AND deleted = ''")->fetchColumn());
        self::assertSame(2, (int) $database->query("SELECT poller_id FROM host WHERE id = 6 AND deleted = 'on'")->fetchColumn());
        foreach (['automation_networks', 'poller_command', 'poller_item', 'poller_output_realtime', 'poller_time'] as $table) {
            self::assertSame(1, (int) $database->query("SELECT poller_id FROM $table WHERE id = 1")->fetchColumn());
        }
        self::assertSame(0, (int) $database->query('SELECT COUNT(*) FROM automation_processes')->fetchColumn());

    }

    public function testDeleteFailsClosedWhenAutomationProcessStillReferencesCollector(): void
    {
        $database = $this->database();
        $this->seed($database);
        $database->exec("INSERT INTO automation_processes VALUES (1, 2)");

        try {
            (new PdoCollectorBulkMutation())->apply($database, 42, CollectorBulkAction::Delete, new CollectorSelection([2]), static fn() => null);
            self::fail('Expected an active automation process to prevent deletion.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('tracked automation processes', $error->getMessage());
        }

        self::assertSame(1, (int) $database->query('SELECT COUNT(*) FROM poller WHERE id = 2')->fetchColumn());
        self::assertSame(2, (int) $database->query('SELECT poller_id FROM automation_processes WHERE id = 1')->fetchColumn());
        self::assertSame(2, (int) $database->query("SELECT poller_id FROM host WHERE id = 5 AND deleted = ''")->fetchColumn());
        self::assertFalse($database->inTransaction());
    }

    public function testDisableEnableAndMissingCollectorFailureAreAtomic(): void
    {
        $database = $this->database();
        $this->seed($database);
        $mutation = new PdoCollectorBulkMutation();
        $mutation->apply($database, 42, CollectorBulkAction::Disable, new CollectorSelection([2]), static fn() => null);
        self::assertSame('on', $database->query('SELECT disabled FROM poller WHERE id = 2')->fetchColumn());
        $mutation->apply($database, 42, CollectorBulkAction::Enable, new CollectorSelection([2]), static fn() => null);
        self::assertSame('', $database->query('SELECT disabled FROM poller WHERE id = 2')->fetchColumn());

        try {
            $mutation->apply($database, 42, CollectorBulkAction::Disable, new CollectorSelection([2, 99]), static fn() => null);
            self::fail('Expected missing collector failure.');
        } catch (\OutOfBoundsException) {
            self::assertSame('', $database->query('SELECT disabled FROM poller WHERE id = 2')->fetchColumn());
            self::assertFalse($database->inTransaction());
        }
    }

    public function testMutationCannotDeleteOrDisablePrimaryAndSyncMustUseWorker(): void
    {
        $database = $this->database();
        $this->seed($database);
        $mutation = new PdoCollectorBulkMutation();
        foreach (CollectorBulkAction::cases() as $action) {
            try {
                $mutation->apply($database, 42, $action, new CollectorSelection([1]), static fn() => null);
                self::fail('Expected primary or worker guard.');
            } catch (\InvalidArgumentException) {
                self::assertSame(2, (int) $database->query('SELECT COUNT(*) FROM poller')->fetchColumn());
            }
        }
    }

    public function testMutationRechecksAuthorizationInsideItsTransactionBeforeWriting(): void
    {
        $database = $this->database();
        $this->seed($database);
        try {
            (new PdoCollectorBulkMutation())->apply(
                $database,
                42,
                CollectorBulkAction::Disable,
                new CollectorSelection([2]),
                static function (\PDO $transaction, int $actorId): void {
                    self::assertTrue($transaction->inTransaction());
                    self::assertSame(42, $actorId);
                    throw new \Kadupul\CollectorAdministration\Infrastructure\Persistence\CollectorBulkAccessDenied();
                }
            );
            self::fail('Expected locked account authorization to reject the write.');
        } catch (\Kadupul\CollectorAdministration\Infrastructure\Persistence\CollectorBulkAccessDenied) {
            self::assertSame('', $database->query('SELECT disabled FROM poller WHERE id = 2')->fetchColumn());
            self::assertFalse($database->inTransaction());
        }
    }

    public function testBulkActionWorkerReceivesValidatedJsonOverSymfonyProcess(): void
    {
        $directory = sys_get_temp_dir() . '/collector-bulk-worker-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $fakePhp = $directory . '/php-wrapper';
        file_put_contents($fakePhp, <<<'PHP'
#!/usr/bin/env php
<?php
$command = json_decode(stream_get_contents(STDIN), true);
if (basename($argv[1] ?? '') !== 'legacy-collector-bulk-action.php' || $command !== ['actor' => 42, 'action' => 'full-sync', 'ids' => [2, 9]]) {
    exit(1);
}
echo 'KADUPUL_COLLECTOR_ACTION_RESULT=' . json_encode(['actor' => 42, 'action' => 'full-sync', 'successful' => [2], 'failed' => [9]]) . "\n";
PHP);
        chmod($fakePhp, 0700);
        try {
            $database = new \PDO('sqlite::memory:');
            $database->exec('CREATE TABLE settings (name TEXT, value TEXT)');
            $statement = $database->prepare('INSERT INTO settings VALUES (?, ?)');
            $statement->execute(['path_php_binary', $fakePhp]);
            $connection = $this->createMock(DatabaseConnection::class);
            $connection->method('get')->willReturn($database);
            $configuration = $this->createMock(LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['collector_id' => 1]);
            $worker = new LegacyCollectorBulkOperations($connection, $configuration, new PdoCollectorOperatorAuthorization(), $this->createMock(AuditTrail::class), dirname(__DIR__, 2));
            self::assertSame(
                ['successful' => [2], 'failed' => [9]],
                $worker->execute(42, CollectorBulkAction::FullSync, new CollectorSelection([9, 2]))
            );
        } finally {
            unlink($fakePhp);
            rmdir($directory);
        }
    }

    public function testExecutionRechecksRealmThreeAccessBeforeStartingWorker(): void
    {
        $actor = new Actor(42, 'operator');
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn($actor);
        $access->method('canManageDevices')->willReturn(false);
        $operations = $this->createMock(CollectorBulkOperations::class);
        $operations->expects(self::never())->method('execute');
        try {
            (new ExecuteCollectorBulkAction($access, $operations))(CollectorBulkAction::Disable, new CollectorSelection([2]));
            self::fail('Expected access to be denied.');
        } catch (CollectorAccessDenied $error) {
            self::assertFalse($error->unauthenticated);
        }
    }

    public function testRemoteCollectorCannotLaunchLocalFullSyncWorker(): void
    {
        $database = new \PDO('sqlite::memory:');
        $connection = $this->createMock(DatabaseConnection::class);
        $connection->method('get')->willReturn($database);
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 2]);
        $operations = new LegacyCollectorBulkOperations($connection, $configuration, new PdoCollectorOperatorAuthorization(), $this->createMock(AuditTrail::class), dirname(__DIR__, 2));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('only available from the primary data collector');
        $operations->execute(42, CollectorBulkAction::FullSync, new CollectorSelection([2]));
    }

    public function testAuditCapturesAuthorizedSuccessAndCannotReplaceConfirmedResult(): void
    {
        $database = $this->database();
        $this->seed($database);
        $database->exec("INSERT INTO poller VALUES (3, '', 3.5, 4, 1, 3.5, 9)");
        $this->seedAuthorization($database);
        $events = [];
        $audit = $this->createMock(AuditTrail::class);
        $audit->expects(self::exactly(2))->method('record')->willReturnCallback(function (AuditEvent $event) use (&$events): void {
            $events[] = $event;
        });
        $operations = $this->operations($database, $audit);

        $result = $operations->execute(42, CollectorBulkAction::Disable, new CollectorSelection([2, 3]));

        self::assertSame(['successful' => [2, 3], 'failed' => []], $result);
        self::assertSame([42, 42], array_map(static fn(AuditEvent $event): ?int => $event->actorId, $events));
        self::assertSame(['collector.disable', 'collector.disable'], array_map(static fn(AuditEvent $event): string => $event->action, $events));
        self::assertSame(['succeeded', 'succeeded'], array_map(static fn(AuditEvent $event): string => $event->outcome, $events));
        self::assertSame(['2', '3'], array_map(static fn(AuditEvent $event): string => $event->targetId, $events));
        self::assertSame(['allowed', 'allowed'], array_map(static fn(AuditEvent $event): string => $event->decision, $events));
        self::assertCount(1, array_unique(array_map(static fn(AuditEvent $event): string => $event->correlationId, $events)));

        $unavailableAudit = $this->createMock(AuditTrail::class);
        $unavailableAudit->expects(self::once())->method('record')->willThrowException(new \RuntimeException('audit unavailable'));
        $result = $this->operations($database, $unavailableAudit)->execute(42, CollectorBulkAction::Enable, new CollectorSelection([2]));
        self::assertSame(['successful' => [2], 'failed' => []], $result);
        self::assertSame('', $database->query('SELECT disabled FROM poller WHERE id = 2')->fetchColumn());
    }

    public function testAuditReportsRolledBackMutationAsFailed(): void
    {
        $database = $this->database();
        $this->seed($database);
        $this->seedAuthorization($database);
        $events = [];
        $audit = $this->createMock(AuditTrail::class);
        $audit->expects(self::exactly(2))->method('record')->willReturnCallback(function (AuditEvent $event) use (&$events): void {
            $events[] = $event;
        });

        try {
            $this->operations($database, $audit)->execute(42, CollectorBulkAction::Disable, new CollectorSelection([2, 99]));
            self::fail('Expected missing collector to abort the transaction.');
        } catch (\OutOfBoundsException) {
            self::assertSame('', $database->query('SELECT disabled FROM poller WHERE id = 2')->fetchColumn());
            self::assertSame(['failed', 'failed'], array_map(static fn(AuditEvent $event): string => $event->outcome, $events));
        }
    }

    public function testAuditReportsLockedAuthorizationRevocationAsDenied(): void
    {
        $database = $this->database();
        $this->seed($database);
        $this->seedAuthorization($database);
        $database->exec('DELETE FROM user_auth_realm WHERE user_id = 42 AND realm_id = 3');
        $events = [];
        $audit = $this->createMock(AuditTrail::class);
        $audit->expects(self::once())->method('record')->willReturnCallback(function (AuditEvent $event) use (&$events): void {
            $events[] = $event;
        });

        try {
            $this->operations($database, $audit)->execute(42, CollectorBulkAction::Disable, new CollectorSelection([2]));
            self::fail('Expected revoked realm permission to deny the write.');
        } catch (CollectorAccessDenied $error) {
            self::assertFalse($error->unauthenticated);
            self::assertSame('denied', $events[0]->decision);
            self::assertSame('denied', $events[0]->outcome);
            self::assertSame('collector.disable', $events[0]->action);
            self::assertSame('2', $events[0]->targetId);
            self::assertSame('', $database->query('SELECT disabled FROM poller WHERE id = 2')->fetchColumn());
        }
    }

    public function testWorkerAuthorizationRequiresEnabledConsoleAccountAndRealmThreeGrant(): void
    {
        $database = new \PDO('sqlite::memory:');
        $database->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $database->exec('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)');
        $database->exec('CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT, enabled TEXT, locked TEXT, must_change_password TEXT)');
        $database->exec('CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER)');
        $database->exec('CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)');
        $database->exec('CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)');
        $database->exec('CREATE TABLE user_auth_group (id INTEGER PRIMARY KEY, enabled TEXT)');
        $database->exec("INSERT INTO settings VALUES ('auth_method', '1'), ('guest_user', 'guest')");
        $database->exec("INSERT INTO user_auth VALUES (42, 'operator', 'on', '', '')");
        $database->exec('INSERT INTO user_auth_realm VALUES (42, 8)');
        $database->exec("INSERT INTO user_auth_group VALUES (4, 'on')");
        $database->exec('INSERT INTO user_auth_group_members VALUES (4, 42)');
        $database->exec('INSERT INTO user_auth_group_realm VALUES (4, 3)');
        $authorization = new PdoCollectorOperatorAuthorization();

        $database->beginTransaction();
        $authorization->assertCanManage($database, 42);
        self::assertTrue($database->inTransaction());
        $database->commit();

        $database->exec("UPDATE user_auth_group SET enabled = 'off'");
        $database->beginTransaction();
        try {
            $authorization->assertCanManage($database, 42);
            self::fail('Disabled role groups must not authorize collector operations.');
        } catch (\Kadupul\CollectorAdministration\Infrastructure\Persistence\CollectorBulkAccessDenied) {
            $database->rollBack();
        }

        $database->exec("UPDATE user_auth SET must_change_password = 'on'");
        $database->beginTransaction();
        try {
            $authorization->assertCanManage($database, 42);
            self::fail('Accounts that must change passwords must not authorize collector operations.');
        } catch (\Kadupul\CollectorAdministration\Infrastructure\Persistence\CollectorBulkAccessDenied) {
            $database->rollBack();
        }
    }

    public function testFullSyncSubprocessFailsClosedOnLegacySqlErrorAndAdvancesLastSyncOnSuccess(): void
    {
        $directory = sys_get_temp_dir() . '/collector-full-sync-' . bin2hex(random_bytes(8));
        foreach ([
            'bin', 'include', 'lib', 'src/CollectorAdministration/Domain',
            'src/CollectorAdministration/Infrastructure/Persistence',
        ] as $subdirectory) {
            mkdir($directory . '/' . $subdirectory, 0700, true);
        }
        $databasePath = $directory . '/test.sqlite';
        $database = new \PDO('sqlite:' . $databasePath);
        $database->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $database->exec('CREATE TABLE poller (id INTEGER PRIMARY KEY, dbhost TEXT, last_sync TEXT)');
        $database->exec("INSERT INTO poller VALUES (2, 'remote', 'before-sync')");
        $database->exec('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)');
        $database->exec("INSERT INTO settings VALUES ('auth_method', '1'), ('guest_user', 'guest')");
        $database->exec('CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT, enabled TEXT, locked TEXT, must_change_password TEXT)');
        $database->exec("INSERT INTO user_auth VALUES (42, 'operator', 'on', '', '')");
        $database->exec('CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER)');
        $database->exec('INSERT INTO user_auth_realm VALUES (42, 8), (42, 3)');
        $database->exec('CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)');
        $database->exec('CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)');
        $database->exec('CREATE TABLE user_auth_group (id INTEGER PRIMARY KEY, enabled TEXT)');
        $database = null;

        $root = dirname(__DIR__, 2);
        $files = [
            'bin/legacy-collector-bulk-action.php',
            'src/CollectorAdministration/Domain/CollectorBulkAction.php',
            'src/CollectorAdministration/Domain/CollectorSelection.php',
            'src/CollectorAdministration/Infrastructure/Persistence/CollectorBulkAccessDenied.php',
            'src/CollectorAdministration/Infrastructure/Persistence/PdoCollectorOperatorAuthorization.php',
            'src/CollectorAdministration/Infrastructure/Persistence/PdoCollectorFullSynchronizer.php',
        ];
        foreach ($files as $file) {
            file_put_contents($directory . '/' . $file, file_get_contents($root . '/' . $file));
        }
        file_put_contents($directory . '/include/cli_check.php', str_replace(
            ['ROOT_PATH', 'DATABASE_PATH'],
            [var_export($root, true), var_export($databasePath, true)],
            <<<'PHP'
<?php
require ROOT_PATH . '/lib/database.php';
$database = new PDO('sqlite:' . DATABASE_PATH);
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database_sessions = ['test:0:test' => $database];
$database_hostname = 'test';
$database_port = 0;
$database_default = 'test';
$config = [];
$database_total_queries = 0;
$database_log = false;
$database_last_error = '';
$error_logged = [];
$affected_rows = [];
function cacti_log(string $message, bool $echo = false, string $environ = ''): void {}
function cacti_count(array $value): int { return count($value); }
$config = ['poller_id' => 1];
$_SESSION = [];
PHP
        ));
        file_put_contents($directory . '/lib/poller.php', <<<'PHP'
<?php
function replicate_out(int $collectorId, string $class = 'all'): bool
{
    if (getenv('KADUPUL_TEST_SYNC_MODE') === 'error') {
        $result = db_execute_prepared('INSERT INTO missing_sync_table (id) VALUES (?)', [$collectorId]);
        if ($result !== false) {
            throw new RuntimeException('The fixture expected the legacy database helper to reject the SQL statement.');
        }
        throw new Error('Database operation failed.');
    }
    return true;
}
PHP);

        try {
            $input = json_encode(['actor' => 42, 'action' => 'full-sync', 'ids' => [2]], JSON_THROW_ON_ERROR);
            foreach ([['error', [[], [2]], 'before-sync'], ['success', [[2], []], null]] as [$mode, $expected, $timestamp]) {
                $process = new Process([PHP_BINARY, $directory . '/bin/legacy-collector-bulk-action.php'], $directory, ['KADUPUL_TEST_SYNC_MODE' => $mode]);
                $process->setTimeout(30);
                $process->setInput($input);
                $process->run();
                self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
                self::assertMatchesRegularExpression('/^KADUPUL_COLLECTOR_ACTION_RESULT=/', $process->getOutput());
                $result = json_decode(substr(trim($process->getOutput()), strlen('KADUPUL_COLLECTOR_ACTION_RESULT=')), true, 8, JSON_THROW_ON_ERROR);
                self::assertSame(42, $result['actor']);
                self::assertSame('full-sync', $result['action']);
                self::assertSame($expected[0], $result['successful'], $process->getOutput() . $process->getErrorOutput());
                self::assertSame($expected[1], $result['failed']);
                $verify = new \PDO('sqlite:' . $databasePath);
                $actualTimestamp = $verify->query('SELECT last_sync FROM poller WHERE id = 2')->fetchColumn();
                if ($timestamp !== null) {
                    self::assertSame($timestamp, $actualTimestamp);
                } else {
                    self::assertNotSame('before-sync', $actualTimestamp);
                }
            }
        } finally {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($directory);
        }
    }

    private function database(): \PDO
    {
        $database = new \PDO('sqlite::memory:');
        $database->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $database->exec('CREATE TABLE poller (id INTEGER PRIMARY KEY, disabled TEXT, total_time REAL, max_time REAL, min_time REAL, avg_time REAL, total_polls INTEGER)');
        foreach (['host', 'automation_networks', 'automation_processes', 'poller_command', 'poller_item', 'poller_output_realtime', 'poller_time'] as $table) {
            $deleted = $table === 'host' ? ', deleted TEXT' : '';
            $database->exec("CREATE TABLE $table (id INTEGER PRIMARY KEY, poller_id INTEGER$deleted)");
        }
        return $database;
    }

    private function seed(\PDO $database): void
    {
        $database->exec("INSERT INTO poller VALUES (1, '', 3.5, 4, 1, 3.5, 9), (2, '', 3.5, 4, 1, 3.5, 9)");
        $database->exec("INSERT INTO host VALUES (5, 2, ''), (6, 2, 'on')");
        foreach (['automation_networks', 'poller_command', 'poller_item', 'poller_output_realtime', 'poller_time'] as $table) {
            $database->exec("INSERT INTO $table VALUES (1, 2)");
        }
    }

    private function seedAuthorization(\PDO $database): void
    {
        $database->exec('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)');
        $database->exec("INSERT INTO settings VALUES ('auth_method', '1'), ('guest_user', 'guest')");
        $database->exec('CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT, enabled TEXT, locked TEXT, must_change_password TEXT)');
        $database->exec("INSERT INTO user_auth VALUES (42, 'operator', 'on', '', '')");
        $database->exec('CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER)');
        $database->exec('INSERT INTO user_auth_realm VALUES (42, 8), (42, 3)');
        $database->exec('CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)');
        $database->exec('CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)');
        $database->exec('CREATE TABLE user_auth_group (id INTEGER PRIMARY KEY, enabled TEXT)');
    }

    private function operations(\PDO $pdo, AuditTrail $audit): LegacyCollectorBulkOperations
    {
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);
        return new LegacyCollectorBulkOperations($database, $configuration, new PdoCollectorOperatorAuthorization(), $audit, dirname(__DIR__, 2));
    }
}
