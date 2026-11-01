<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\ConsoleOperator;
use Kadupul\Platform\Infrastructure\Legacy\LegacyConnectionRegistry;
use Kadupul\Platform\Infrastructure\Legacy\NativeInstallerBackend;
use Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class InstallerNativeStateTest extends TestCase
{
    private \PDO $database;
    private \PDO $peer;
    private string $schema;
    private NativeInstallerBackend $backend;
    private bool $created = false;

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')) {
            $this->markTestSkipped('An explicitly configured disposable MySQL/MariaDB server is required.');
        }
        $user = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root');
        $password = getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '');
        $this->database = new \PDO($dsn, $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->peer = new \PDO($dsn, $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->schema = 'kadupul_installer13_' . bin2hex(random_bytes(12));
        $this->database->exec('CREATE DATABASE ' . $this->schema . ' CHARACTER SET utf8mb4');
        $this->created = true;
        $this->database->exec('USE ' . $this->schema);
        $this->peer->exec('USE ' . $this->schema);
        $source = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        $this->assertIsString($source);
        foreach (['settings', 'version'] as $table) {
            $this->assertSame(1, preg_match('~CREATE TABLE [`]?' . preg_quote($table, '~') . '[`]?\\s*\\(.*?;~s', $source, $match));
            $this->database->exec($match[0]);
        }
        $this->database->exec("INSERT INTO version (cacti) VALUES ('new_install')");
        define('CACTI_VERSION', trim(file_get_contents(dirname(__DIR__, 2) . '/include/cacti_version')));
        $GLOBALS['database_hostname'] = 'installer-fixture';
        $GLOBALS['database_port'] = '0';
        $GLOBALS['database_default'] = $this->schema;
        $GLOBALS['local_db_cnn_id'] = null;
        LegacyConnectionRegistry::setDefaultSession($this->database);
        $this->backend = new NativeInstallerBackend(dirname(__DIR__, 2), $this->createMock(ConsoleOperator::class), new NativeInstallerFunctions());
    }

    protected function tearDown(): void
    {
        if ($this->created) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            $this->database->exec('DROP DATABASE ' . $this->schema);
        }
    }

    private function store(string $name, string $value): void
    {
        $statement = $this->database->prepare('REPLACE INTO settings (name, value) VALUES (?, ?)');
        $statement->execute([$name, $value]);
    }

    private function job(string $state, ?string $target = null): string
    {
        return json_encode(['state' => $state, 'token' => str_repeat('a', 32), 'actor' => 'fixture-admin',
            'actor_id' => 1, 'revision' => str_repeat('a', 64), 'target' => $target ?? CACTI_VERSION, 'started' => 1, 'attempt' => 1], JSON_THROW_ON_ERROR);
    }

    public function testStatusPreservesFailureAndDoesNotMutateSettings(): void
    {
        $this->store('installer_v13_job', $this->job('failed'));
        $this->store('install_progress', '41');
        $this->store('install_error', 'private-diagnostic-canary');
        $before = $this->database->query('SELECT name, value FROM settings ORDER BY name')->fetchAll();
        $status = $this->backend->status();
        $this->assertSame('failed', $status['state']);
        $this->assertSame(41, $status['progress']);
        $this->assertStringNotContainsString('private-diagnostic-canary', json_encode($status));
        $this->assertSame($before, $this->database->query('SELECT name, value FROM settings ORDER BY name')->fetchAll());
    }

    public function testTheActualLockExcludesAnotherSessionAndReleasesAfterNestedUse(): void
    {
        $lock = new \ReflectionMethod(NativeInstallerBackend::class, 'locked');
        $entered = 0;
        $lock->invoke($this->backend, function () use ($lock, &$entered): void {
            $this->assertSame(37, $lock->invoke($this->backend, static fn(): int => 37));
            LegacyConnectionRegistry::setDefaultSession($this->peer);
            try {
                $lock->invoke($this->backend, function () use (&$entered): void {
                    ++$entered;
                });
                $this->fail('A second connection entered the live worker lock.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('busy', $error->getMessage());
            } finally {
                LegacyConnectionRegistry::setDefaultSession($this->database);
            }
        });
        $this->assertSame(0, $entered);
        LegacyConnectionRegistry::setDefaultSession($this->peer);
        $this->assertSame(99, $lock->invoke($this->backend, static fn(): int => 99));
    }

    public function testOperationFailurePreservesTheCallersOpenTransaction(): void
    {
        $this->database->beginTransaction();
        $this->store('caller_owned', 'keep');
        $lock = new \ReflectionMethod(NativeInstallerBackend::class, 'locked');
        try {
            $lock->invoke($this->backend, static function (): never {
                throw new \RuntimeException('operation failed');
            });
            $this->fail('Expected the original operation failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('operation failed', $error->getMessage());
        }
        $this->assertTrue($this->database->inTransaction());
        $this->assertSame('keep', $this->database->query("SELECT value FROM settings WHERE name = 'caller_owned'")->fetchColumn());
    }

    public function testStatusRejectsDivergentLocalAndDefaultMutationSessions(): void
    {
        $GLOBALS['local_db_cnn_id'] = $this->peer;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('local mutation connection');
        $this->backend->status();
    }

    public function testConfigurationRejectsATemporaryShadowOfTheRealInnoDbSettingsTable(): void
    {
        $definition = $this->database->query('SHOW CREATE TABLE settings')->fetch(\PDO::FETCH_ASSOC)['Create Table'];
        $this->database->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $definition));
        $this->database->beginTransaction();
        try {
            (new \ReflectionMethod(NativeInstallerBackend::class, 'configurationStorage'))->invoke($this->backend);
            $this->fail('Temporary settings shadow was admitted.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('persistent InnoDB', $error->getMessage());
            $this->assertTrue($this->database->inTransaction());
        } finally {
            $this->database->rollBack();
            $this->database->exec('DROP TEMPORARY TABLE settings');
        }
    }

    public static function malformedJobs(): iterable
    {
        yield 'array' => ['[]'];
        yield 'truncated receipt' => ['{"state":"succeeded"}'];
        yield 'invalid JSON' => ['{broken'];
    }

    #[DataProvider('malformedJobs')]
    public function testMalformedJobCannotPublishSuccessOrOverwriteEvidence(string $value): void
    {
        $this->store('installer_v13_job', $value);
        try {
            $this->backend->status();
            $this->fail('Invalid job receipt was admitted.');
        } catch (\RuntimeException|\JsonException) {
            $this->assertSame($value, $this->database->query("SELECT value FROM settings WHERE name = 'installer_v13_job'")->fetchColumn());
        }
    }

    public function testCompletedJobFromAnEarlierTargetDoesNotClaimTheNextUpgradeIsDone(): void
    {
        $this->store('installer_v13_job', $this->job('succeeded', '0.0.0'));
        $this->store('install_progress', '100');
        $status = $this->backend->status();
        $this->assertSame('not_started', $status['state']);
        $this->assertSame(0, $status['progress']);
        $this->assertSame('new_install', $status['schema']);
        $this->assertStringContainsString('0.0.0', $this->database->query("SELECT value FROM settings WHERE name = 'installer_v13_job'")->fetchColumn());
    }

    public function testSuccessReceiptCannotOverrideTheActualSchemaVersion(): void
    {
        $receipt = $this->job('succeeded');
        $this->store('installer_v13_job', $receipt);
        $this->assertSame('failed', $this->backend->status()['state']);
        $this->assertSame($receipt, $this->database->query("SELECT value FROM settings WHERE name = 'installer_v13_job'")->fetchColumn());
    }

    public function testPartialReceiptCannotClaimThatAnIncompleteSchemaIsInstalled(): void
    {
        $receipt = $this->job('partial');
        $this->store('installer_v13_job', $receipt);
        $status = $this->backend->status();
        $this->assertSame('failed', $status['state']);
        $this->assertStringNotContainsString('The database is installed', $status['error']);
        $this->assertSame($receipt, $this->database->query("SELECT value FROM settings WHERE name = 'installer_v13_job'")->fetchColumn());
    }

    public function testStateWritesConfirmExactPersistedBytesAndPermitUnchangedValues(): void
    {
        $write = new \ReflectionMethod(NativeInstallerBackend::class, 'write');
        $write->invoke($this->backend, 'fixture_receipt', 'Confirmed');
        $write->invoke($this->backend, 'fixture_receipt', 'Confirmed');
        $this->assertSame('Confirmed', $this->database->query("SELECT value FROM settings WHERE name = 'fixture_receipt'")->fetchColumn());
        $this->database->exec("CREATE TRIGGER fixture_coercion BEFORE UPDATE ON settings FOR EACH ROW SET NEW.value = LOWER(NEW.value)");
        try {
            $write->invoke($this->backend, 'fixture_receipt', 'Changed');
            $this->fail('A coerced receipt was reported as confirmed.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('persistence could not be confirmed', $error->getMessage());
        }
        $this->assertSame('changed', $this->database->query("SELECT value FROM settings WHERE name = 'fixture_receipt'")->fetchColumn());
    }

    public function testNativeCompletionRetainsCurrentAndPreviousWorkflowReceipts(): void
    {
        $GLOBALS['installer_fixture_database'] = $this->database;
        $GLOBALS['installer_fixture_cleanup_calls'] = 0;
        eval('function db_execute($sql) { return $GLOBALS["installer_fixture_database"]->exec($sql) !== false; }
            function api_plugin_uninstall_integrated() { ++$GLOBALS["installer_fixture_cleanup_calls"]; }');
        require dirname(__DIR__, 2) . '/lib/installer.php';
        $this->store('install_step', '98');
        $this->store('install_snmp_option_snmp_community', 'private-cleanup-canary');
        $this->store('installer_v13_job', 'current-receipt');
        $this->store('installer_v13_previous_job', 'previous-receipt');
        \Installer::finishReviewedInstallation();
        $rows = $this->database->query('SELECT name, value FROM settings ORDER BY name')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame(['installer_v13_job' => 'current-receipt', 'installer_v13_previous_job' => 'previous-receipt'], $rows);
        $this->assertSame(1, $GLOBALS['installer_fixture_cleanup_calls']);
    }

    public static function sessionEligibility(): iterable
    {
        yield 'eligible' => [0, true];
        yield 'credential revoked' => [401, false];
        yield 'account disabled' => [403, false];
    }

    #[DataProvider('sessionEligibility')]
    public function testEveryNonzeroNativeEligibilityOutcomeDeniesBeforeRealmLookup(int $outcome, bool $allowed): void
    {
        define('IN_CACTI_INSTALL', true);
        define('KADUPUL_INSTALLER_WEB', true);
        $_SESSION = ['sess_user_id' => 1];
        $GLOBALS['installer_fixture_eligibility'] = $outcome;
        $GLOBALS['installer_fixture_realm_calls'] = 0;
        eval('function read_config_option($name) { return 1; }
            function auth_session_check_eligibility($method) { return $GLOBALS["installer_fixture_eligibility"]; }
            function is_realm_allowed($realm, $id) { ++$GLOBALS["installer_fixture_realm_calls"]; return true; }');
        try {
            $this->backend->authorize();
            $this->assertTrue($allowed, 'A revoked native session was admitted.');
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException) {
            $this->assertFalse($allowed, 'An eligible session was rejected.');
        }
        $this->assertSame($allowed ? 1 : 0, $GLOBALS['installer_fixture_realm_calls']);
    }

    public function testDisabledAuthenticationCannotBorrowAnEarlierSessionIdentity(): void
    {
        define('IN_CACTI_INSTALL', true);
        define('KADUPUL_INSTALLER_WEB', true);
        $_SESSION = ['sess_user_id' => 1];
        eval('function read_config_option($name) { return 0; }
            function auth_session_check_eligibility($method) { return 0; }
            function is_realm_allowed($realm, $id) { return true; }');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);
        $this->backend->authorize();
    }

    public function testWorkerRefusesAnUncommittedReservationWithoutTouchingCallerWork(): void
    {
        $this->database->beginTransaction();
        $this->store('caller_owned', 'retained');
        try {
            $this->backend->execute(str_repeat('a', 32));
            $this->fail('Worker accepted a caller-owned transaction.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('caller-owned transaction', $error->getMessage());
        }
        $this->assertTrue($this->database->inTransaction());
        $this->assertSame('retained', $this->database->query("SELECT value FROM settings WHERE name='caller_owned'")->fetchColumn());
    }

    public function testExplicitRetryCannotReplaceAnActiveReservation(): void
    {
        define('IN_CACTI_INSTALL', true);
        define('KADUPUL_INSTALLER_WEB', true);
        $_SESSION = ['sess_user_id' => 1];
        eval('function read_config_option($name) { return 1; }
            function auth_session_check_eligibility($method) { return 0; }
            function is_realm_allowed($realm, $id) { return true; }');
        $receipt = $this->job('waiting');
        $this->store('installer_v13_job', $receipt);
        try {
            $this->backend->start(str_repeat('a', 64), true, true);
            $this->fail('Retry overwrote an active reservation.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('still active', $error->getMessage());
        }
        $this->assertSame($receipt, $this->database->query("SELECT value FROM settings WHERE name='installer_v13_job'")->fetchColumn());
    }

    public function testWorkerRejectsDisabledAutocommitBeforeItsFirstTableRead(): void
    {
        $this->database->exec('SET SESSION autocommit = 0');
        $this->assertFalse($this->database->inTransaction());
        try {
            $this->backend->execute(str_repeat('a', 32));
            $this->fail('Worker accepted a session that would leave its receipt uncommitted.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('require autocommit', $error->getMessage());
            $this->assertFalse($this->database->inTransaction());
        } finally {
            $this->database->exec('SET SESSION autocommit = 1');
        }
        $this->assertSame([], $this->database->query('SELECT name FROM settings')->fetchAll());
    }

    public function testNativeSnmpOverrideAcceptsExplicitZeroAndRetainsOmittedValues(): void
    {
        $GLOBALS['local_db_cnn_id'] = $this->database;
        require dirname(__DIR__) . '/Helpers/PhpSource.php';
        $source = file_get_contents(dirname(__DIR__, 2) . '/install/functions.php');
        $this->assertIsString($source);
        eval(\test_php_function_source($source, 'set_install_config_option'));
        eval('function db_execute_prepared($sql, $parameters, $log = false, $database = null) { return $database->prepare($sql)->execute($parameters); }
            function log_install_high(...$arguments) {}
            function log_install_medium(...$arguments) {}');
        require dirname(__DIR__, 2) . '/lib/installer.php';
        $installer = (new \ReflectionClass(\Installer::class))->newInstanceWithoutConstructor();
        $setter = new \ReflectionMethod(\Installer::class, 'setAutomationOverride');
        $setter->invoke($installer, 1);
        $setter->invoke($installer, null);
        $this->assertSame('1', $this->database->query("SELECT value FROM settings WHERE name='install_automation_override'")->fetchColumn());
        $setter->invoke($installer, 0);
        $this->assertSame('0', $this->database->query("SELECT value FROM settings WHERE name='install_automation_override'")->fetchColumn());
        $this->assertFalse((new \ReflectionProperty(\Installer::class, 'automationOverride'))->getValue($installer));
    }
}
