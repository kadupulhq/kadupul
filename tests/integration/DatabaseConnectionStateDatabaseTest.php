<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

/**
 * The connection state lib/database.php and include/global.php leave behind,
 * on the main poller and on a collector, and the retry, reconnect and close
 * paths of the helpers. Recorded before that state moved behind
 * LegacyConnectionRegistry; any change here is a contract change.
 */
final class DatabaseConnectionStateDatabaseTest extends TestCase
{
    private static ?PDO $admin = null;

    /** @var array{local: string, main: string} */
    private static array $schemas;

    /** @var array<string, array<string, mixed>> */
    private static array $runs = [];

    public static function setUpBeforeClass(): void
    {
        if (!getenv('KADUPUL_TEST_MYSQL_DSN')) {
            return;
        }
        parse_str(str_replace(';', '&', substr((string) getenv('KADUPUL_TEST_MYSQL_DSN'), strlen('mysql:'))), $dsn);
        $prefix = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ? 'KADUPUL_TEST_MYSQL_ADMIN_' : 'KADUPUL_TEST_MYSQL_';
        self::$admin = new PDO('mysql:host=' . $dsn['host'] . ';port=' . ($dsn['port'] ?? '3306'), getenv($prefix . 'USER') ?: 'root', getenv($prefix . 'PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $suffix = bin2hex(random_bytes(5));
        self::$schemas = ['local' => 'kconn_local_' . $suffix, 'main' => 'kconn_main_' . $suffix];
        foreach (self::$schemas as $name) {
            self::$admin->exec("CREATE DATABASE `$name`");
            self::$admin->exec("USE `$name`");
            self::seed(self::$admin);
        }
    }

    /** cacti.sql as the installer reads it, DELIMITER blocks included. */
    private static function seed(PDO $database): void
    {
        $delimiter = ';';
        $buffer = '';
        foreach (file(dirname(__DIR__, 2) . '/cacti.sql') ?: [] as $line) {
            if (preg_match('/^DELIMITER (\S+)\s*$/', trim($line), $matches)) {
                self::assertSame('', trim($buffer), 'cacti.sql changes delimiter inside a statement');
                $delimiter = $matches[1];
                continue;
            }
            if (str_starts_with(ltrim($line), '--') || trim($line) === '') {
                continue;
            }
            $buffer .= $line;
            if (str_ends_with(rtrim($buffer), $delimiter)) {
                $database->exec(substr(rtrim($buffer), 0, -strlen($delimiter)));
                $buffer = '';
            }
        }
        self::assertSame('', trim($buffer), 'cacti.sql ends inside a statement');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$admin !== null) {
            foreach (self::$schemas as $name) {
                self::$admin->exec("DROP DATABASE IF EXISTS `$name`");
            }
        }
        self::$admin = null;
    }

    protected function setUp(): void
    {
        if (!getenv('KADUPUL_TEST_MYSQL_DSN')) {
            self::markTestSkipped('KADUPUL_TEST_MYSQL_DSN is required for database contracts');
        }
    }

    public function testTheMainPollerBootstrapsOneDefaultSession(): void
    {
        $state = $this->runNative('bootstrap', ['poller_id' => 1]);
        self::assertSame($this->localGlobals() + $this->collectorGlobals(null) + ['local_db_cnn_id' => ['null', null], 'remote_db_cnn_id' => ['null', null], 'conn_mode' => ['null', null]], $state['globals']);
        self::assertSame('online', $state['connection']);
        self::assertSame('string', $state['cacti_db_version']);
        self::assertSame([], $state['database_error_codes']);
        self::assertSame(['{host}:{port}:{local}' => 'default'], $state['sessions']);
        self::assertSame([$this->details('{local}')], $state['details']);
        self::assertSame('local', $state['default_database']);
        // Only a collector switches; the main poller keeps its session.
        self::assertSame(['false', 'local'], [$state['force_remote'], $state['default_after_force']]);
    }

    public function testAnOnlineCollectorHoldsBothSessionsAndDefaultsToLocalOnTheCommandLine(): void
    {
        $state = $this->runNative('bootstrap', ['poller_id' => 2, 'remote' => true]);
        self::assertSame($this->localGlobals() + $this->collectorGlobals('{port}') + ['local_db_cnn_id' => 'PDO local', 'remote_db_cnn_id' => 'PDO remote', 'conn_mode' => ['null', null]], $state['globals']);
        self::assertSame('online', $state['connection']);
        self::assertSame([], $state['database_error_codes']);
        self::assertSame(['{host}:{port}:{local}' => 'local', '{host}:{port}:{main}' => 'remote'], $state['sessions']);
        self::assertSame([$this->details('{local}'), $this->details('{main}')], $state['details']);
        self::assertSame('local', $state['default_database']);
        self::assertSame(['true', 'main'], [$state['force_remote'], $state['default_after_force']]);
    }

    public function testACollectorThatCannotReachTheMainServerIsOffline(): void
    {
        $state = $this->runNative('bootstrap', ['poller_id' => 2, 'remote' => true, 'remote_port' => '1']);
        self::assertSame($this->localGlobals() + $this->collectorGlobals('1') + ['local_db_cnn_id' => 'PDO local', 'remote_db_cnn_id' => ['bool', false], 'conn_mode' => ['null', null]], $state['globals']);
        self::assertSame('offline', $state['connection']);
        // global.php's default of two retries: three attempts.
        self::assertSame([2002, 2002, 2002], $state['database_error_codes']);
        self::assertSame(['{host}:{port}:{local}' => 'local'], $state['sessions']);
        self::assertSame([$this->details('{local}')], $state['details']);
        self::assertSame('local', $state['default_database']);
        // The switch still runs and leaves the default key holding false.
        self::assertSame(['true', 'false'], [$state['force_remote'], $state['default_after_force']]);
    }

    public function testAForcedOfflineCollectorNeverConnectsToTheMainServer(): void
    {
        $state = $this->runNative('bootstrap', ['poller_id' => 2, 'remote' => true, 'conn_mode' => 'offline']);
        self::assertSame($this->localGlobals() + $this->collectorGlobals('{port}') + ['local_db_cnn_id' => 'PDO local', 'remote_db_cnn_id' => ['null', null], 'conn_mode' => ['string', 'offline']], $state['globals']);
        self::assertSame('offline', $state['connection']);
        self::assertSame([], $state['database_error_codes']);
        self::assertSame(['{host}:{port}:{local}' => 'local'], $state['sessions']);
        self::assertSame(['true', 'false'], [$state['force_remote'], $state['default_after_force']]);
    }

    public function testConnectRegistersTheSessionAndItsReconnectSettings(): void
    {
        $connect = $this->runNative('helpers', [])['connect'];
        self::assertSame(['type' => 'PDO', 'cached' => true, 'total_queries_after_connect' => 0, 'errmode' => PDO::ERRMODE_SILENT, 'persistent' => false, 'default_database' => 'local', 'config_read' => false], array_diff_key($connect, array_flip(['sql_mode', 'charset', 'sessions', 'details'])));
        self::assertContains('ALLOW_INVALID_DATES', $connect['sql_mode']);
        self::assertSame([], array_intersect(['STRICT_TRANS_TABLES', 'STRICT_ALL_TABLES', 'TRADITIONAL', 'NO_ZERO_DATE', 'NO_ZERO_IN_DATE', 'ONLY_FULL_GROUP_BY', 'NO_AUTO_VALUE_ON_ZERO'], $connect['sql_mode']));
        self::assertContains($connect['charset'], ['utf8', 'utf8mb3']);
        self::assertSame(['{host}:{port}:{local}' => 'local'], $connect['sessions']);
        self::assertSame([$this->details('{local}')], $connect['details']);
    }

    public function testASecondConnectionKeepsItsOwnKeyAndTheDefaultFollowsTheGlobals(): void
    {
        $remote = $this->runNative('helpers', [])['remote'];
        self::assertSame(['type' => 'PDO', 'distinct' => true, 'persistent' => true, 'default_before_override' => 'local', 'explicit' => true], array_slice($remote, 0, 5, true));
        self::assertSame(['{host}:{port}:{local}' => 'local', '{host}:{port}:{main}' => 'remote'], $remote['sessions']);
        self::assertSame([$this->details('{local}'), $this->details('{main}')], $remote['details']);
        self::assertSame(['main', 'true', 'main', 'true', 'local'], [$remote['default_after_override'], $remote['force'], $remote['default_after_force'], $remote['local'], $remote['default_after_local']]);
    }

    public function testARefusedConnectionRecordsEachAttemptWithoutTheCredentials(): void
    {
        self::assertSame(['result' => 'false', 'codes' => [2002, 2002], 'leaks' => false, 'sessions_unchanged' => true], $this->runNative('helpers', [])['refused']);
        self::assertSame(['result' => true, 'sessions_unchanged' => true, 'config_read' => true], $this->runNative('helpers', [])['check_alive']);
    }

    public function testADroppedSessionIsReplacedFromConfigWhileTheFailingCallReturnsFalse(): void
    {
        $reconnect = $this->runNative('helpers', [])['reconnect'];
        // Level 2 is POLLER_VERBOSITY_LOW.
        $warning = ['WARNING: The DB has gone away during a query.  Retry to connect and query in 5 seconds.', 'DBCALL', 2];
        self::assertSame(['dropped' => 'false', 'logged' => array_fill(0, 5, $warning), 'error' => 'DB Cell Failed!, Error 2006: MySQL server has gone away', 'config_read' => true, 'replaced' => true, 'next' => 42, 'new_session' => true, 'remote_kept' => true], array_diff_key($reconnect, array_flip(['sessions', 'details'])));
        self::assertSame(['{host}:{port}:{local}' => 'replacement', '{host}:{port}:{main}' => 'remote'], $reconnect['sessions']);
        // Each of the five retries reconnects, and every handle it replaces
        // stays registered (and open) in $database_details: local, main and
        // five replacements.
        self::assertCount(7, $reconnect['details']);
    }

    public function testCloseReleasesTheDefaultKeyAndAPassedHandle(): void
    {
        self::assertSame(['default' => true, 'session' => 'null', 'after' => 'false', 'error' => 'DB Cell -- No connection found', 'again' => false, 'explicit' => true, 'explicit_after' => 'null', 'remote_still_open' => true], $this->runNative('helpers', [])['close']);
    }

    /** @return array<string, mixed> the database_* globals global.php leaves from config.php */
    private function localGlobals(): array
    {
        return [
            'database_type' => ['string', 'mysql'], 'database_default' => ['string', '{local}'], 'database_hostname' => ['string', '{host}'],
            'database_username' => ['string', '{user}'], 'database_password' => 'string', 'database_port' => ['string', '{port}'],
            'database_retries' => ['int', 2], 'database_ssl' => ['bool', false], 'database_ssl_key' => ['string', ''],
            'database_ssl_cert' => ['string', ''], 'database_ssl_ca' => ['string', ''], 'database_persist' => ['bool', true],
        ];
    }

    /** @return array<string, mixed> the rdatabase_* globals, unset on the main poller */
    private function collectorGlobals(?string $port): array
    {
        $names = ['type', 'default', 'hostname', 'username', 'password', 'port', 'retries', 'ssl', 'ssl_key', 'ssl_cert', 'ssl_ca'];
        if ($port === null) {
            return array_fill_keys(array_map(static fn(string $name): string => 'rdatabase_' . $name, $names), 'unset');
        }

        return [
            'rdatabase_type' => ['string', 'mysql'], 'rdatabase_default' => ['string', '{main}'], 'rdatabase_hostname' => ['string', '{host}'],
            'rdatabase_username' => ['string', '{user}'], 'rdatabase_password' => 'string', 'rdatabase_port' => ['string', $port],
            // Filled in by global.php, which config.php leaves to it here.
            'rdatabase_retries' => ['int', 2], 'rdatabase_ssl' => ['bool', false], 'rdatabase_ssl_key' => ['string', ''],
            'rdatabase_ssl_cert' => ['string', ''], 'rdatabase_ssl_ca' => ['string', ''],
        ];
    }

    /** @return array<string, mixed> one $database_details entry as db_connect_real() writes it */
    private function details(string $schema): array
    {
        return [
            'database_conn' => 'own handle', 'database_hostname' => ['string', '{host}'], 'database_username' => ['string', '{user}'],
            'database_password' => ['string', true], 'database_default' => ['string', $schema], 'database_type' => ['string', 'mysql'],
            'database_port' => ['string', '{port}'], 'database_retries' => ['int', 2], 'database_ssl' => ['bool', false],
            'database_ssl_key' => ['string', ''], 'database_ssl_cert' => ['string', ''], 'database_ssl_ca' => ['string', ''],
            'database_persist' => ['bool', false],
        ];
    }

    /** @return array<string, mixed> */
    private function runNative(string $mode, array $options): array
    {
        $options += self::$schemas;
        $key = $mode . json_encode($options);
        if (isset(self::$runs[$key])) {
            return self::$runs[$key];
        }
        // Arguments stay out of traces: db_connect_real() takes the password.
        $process = proc_open([PHP_BINARY, '-d', 'error_reporting=' . error_reporting(), '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'zend.exception_ignore_args=1', dirname(__DIR__) . '/Fixtures/database-connection-state-native.php', $mode, json_encode($options, JSON_THROW_ON_ERROR)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Connection state fixture did not start');
        }
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error . $output);
        self::assertSame('', $error);

        return self::$runs[$key] = json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR);
    }
}
