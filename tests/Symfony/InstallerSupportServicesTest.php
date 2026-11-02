<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Tests\Fixtures\InstallerReadFailureStream;
use Kadupul\Platform\Domain\Installer\MigrationStatus;
use Kadupul\Platform\Infrastructure\Legacy\InstallerAutomationRepair;
use Kadupul\Platform\Infrastructure\Legacy\InstallerCollectorConfiguration;
use Kadupul\Platform\Infrastructure\Legacy\InstallerCollectorSynchronization;
use Kadupul\Platform\Infrastructure\Legacy\InstallerColorImport;
use Kadupul\Platform\Infrastructure\Legacy\InstallerConfigurationWriter;
use Kadupul\Platform\Infrastructure\Legacy\InstallerCsrfSecret;
use Kadupul\Platform\Infrastructure\Legacy\InstallerDatabaseDiagnostics;
use Kadupul\Platform\Infrastructure\Legacy\InstallerDefaultSettings;
use Kadupul\Platform\Infrastructure\Legacy\InstallerLogger;
use Kadupul\Platform\Infrastructure\Legacy\InstallerMigrationDatabase;
use Kadupul\Platform\Infrastructure\Legacy\InstallerMigrationProgress;
use Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

/** Exercise extracted rules through their actual native boundary and task-owned resources. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class InstallerSupportServicesTest extends TestCase
{
    /** @var array<string, \Closure> Failure injection belongs at the external native operations. */
    public static array $handlers = [];
    /** @var list<array{0: string, 1: list<mixed>}> Exact native handoffs in order. */
    public static array $calls = [];
    /** @var array<string, mixed> Mutable native settings for each isolated test. */
    public static array $options = [];
    /** Task-owned fixture directory, never an application installation. */
    private string $directory;
    /** Typed native bridge under test. */
    private NativeInstallerFunctions $native;

    /** Preserve producer signatures while allowing independent external outcomes. */
    public static function invoke(string $name, array $arguments): mixed
    {
        self::$calls[] = [$name, $arguments];
        if (isset(self::$handlers[$name])) {
            return (self::$handlers[$name])(...$arguments);
        }
        return match ($name) {
            'read_config_option' => self::$options[$arguments[0]] ?? '',
            'config_value_exists' => array_key_exists($arguments[0], self::$options),
            'clean_up_lines' => $arguments[0],
            '__' => count($arguments) === 1 ? $arguments[0] : vsprintf($arguments[0], array_slice($arguments, 1)),
            'db_error' => $GLOBALS['database_last_error'] ?? false,
            'db_fetch_cell_prepared', 'db_fetch_cell' => false,
            'db_fetch_row_prepared', 'db_fetch_assoc_prepared', 'db_fetch_assoc' => [],
            'db_execute_prepared', 'db_execute', 'db_table_exists', 'db_column_exists', 'db_add_column', 'db_remove_column' => true,
            'db_index_matches' => 0,
            'db_index_exists', 'db_connect_real', 'replicate_out', 'sql_save' => false,
            'cacti_csrf_external_path_is_safe' => true,
            'csrf_get_secret' => 'disposable-generated-fixture-secret',
            default => null,
        };
    }

    /** Every test uses a fresh runtime and a private writable installation skeleton. */
    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        require $root . '/include/global_constants.php';
        require $root . '/install/functions.php';
        require $root . '/tests/Fixtures/installer-support-hooks.php';
        $this->directory = sys_get_temp_dir() . '/installer-services-' . bin2hex(random_bytes(10));
        mkdir($this->directory, 0700);
        mkdir($this->directory . '/log', 0700);
        $this->native = new NativeInstallerFunctions();
        $GLOBALS['config'] = ['base_path' => $this->directory, 'cacti_db_version' => '1.2.30'];
        $GLOBALS['database_last_error'] = false;
        $GLOBALS['cacti_upgrade_version'] = '1.3.0';
        $GLOBALS['database_upgrade_status'] = [];
        $_SESSION = [];
    }

    /** Remove only the private fixture tree created by this test. */
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    /** Assertions inspect progress separately from PHPUnit output. */
    private function quiet(\Closure $operation): mixed
    {
        ob_start();
        try {
            return $operation();
        } finally {
            ob_end_clean();
        }
    }

    /** Stored empty rows, zero values, nested defaults and pre-upgrade LDAP policy retain their identities. */
    public function testDefaultPrimingPreservesStoredValuesAndOnlyMarksConfirmedCompletion(): void
    {
        $stored = ['kept' => '0', 'empty' => '', 'ldap_encryption' => '1'];
        self::$handlers['db_fetch_cell_prepared'] = static fn(string $sql, array $parameters): mixed => $stored[$parameters[0]] ?? false;
        self::$handlers['db_fetch_cell'] = static fn(): string => '0';
        self::$handlers['db_execute_prepared'] = static function (string $sql, array $parameters) use (&$stored): bool {
            $stored[$parameters[0]] ??= $parameters[1];
            return true;
        };
        $GLOBALS['settings'] = ['ignored' => null, 'main' => [
            'kept' => ['default' => 'replacement'], 'empty' => ['default' => 'replacement'],
            'fresh' => ['default' => 'new'], 'ldap_tls_certificate' => ['default' => 2],
            'nested' => ['items' => ['nested_value' => ['default' => 0], 'ignored' => [], 2 => 'malformed']],
            'ignored' => 'invalid',
        ]];
        \prime_default_settings();
        $this->assertSame(['kept' => '0', 'empty' => '', 'ldap_encryption' => '1', 'fresh' => 'new', 'ldap_tls_certificate' => 0, 'nested_value' => 0], $stored);
        $this->assertTrue($_SESSION['settings_primed']);
        $count = count(self::$calls);
        \prime_default_settings();
        $this->assertCount($count, self::$calls);
        $defaults = new InstallerDefaultSettings($this->native);
        $this->assertSame(2, $defaults->ldapDefault(2, 'new_install'));
        $this->assertSame(2, $defaults->ldapDefault(2, ''));
        self::$handlers['db_fetch_cell_prepared'] = static fn(): string => '0';
        $this->assertSame(2, $defaults->ldapDefault(2, '1.2.30'));
        self::$handlers['db_fetch_cell'] = static fn(): string => '1';
        $this->assertSame(0, $defaults->ldapDefault(2, '1.2.30'));
        self::$handlers['db_execute_prepared'] = static fn(): bool => false;
        self::$handlers['db_fetch_cell_prepared'] = static fn(): bool => false;
        unset($_SESSION['settings_primed']);
        try {
            \prime_default_settings();
            $this->fail('Failed priming marked complete.');
        } catch (\RuntimeException) {
            $this->assertArrayNotHasKey('settings_primed', $_SESSION);
        }
    }

    /** The writer uses the explicitly selected local PDO and leaves other settings intact. */
    public function testConfigurationWriterUsesExactLocalConnectionAndReportsFailure(): void
    {
        $database = new \PDO('sqlite::memory:');
        $database->exec('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)');
        $database->exec("INSERT INTO settings VALUES ('retained', 'unchanged')");
        self::$handlers['db_execute_prepared'] = function (string $sql, array $parameters, bool $log, object $connection) use ($database): bool {
            $this->assertSame($database, $connection);
            $this->assertFalse($log);
            return $connection->prepare($sql)->execute($parameters);
        };
        $writer = new InstallerConfigurationWriter($this->native);
        $writer->write('path_cactilog', '/wrong.txt', $this->directory, $database);
        $writer->write('path_stderrlog', '/accepted.log', $this->directory, $database);
        $this->assertSame('unchanged', $database->query("SELECT value FROM settings WHERE name='retained'")->fetchColumn());
        $this->assertSame($this->directory . '/log/cacti.log', $database->query("SELECT value FROM settings WHERE name='path_cactilog'")->fetchColumn());
        $this->assertSame('/accepted.log', $database->query("SELECT value FROM settings WHERE name='path_stderrlog'")->fetchColumn());
        $writer->write('another', 'value', $this->directory, null);
        $this->assertSame('set_config_option', self::$calls[array_key_last(self::$calls)][0]);
        self::$handlers['db_execute_prepared'] = static fn(): bool => false;
        try {
            $writer->write('another', 'value', $this->directory, $database);
            $this->fail('Write failure ignored.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('could not be stored', $error->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        $writer->write('path_cactilog', [], $this->directory, null);
    }

    /** Numeric string verbosity and force-echo remain independent from file logging. */
    public function testLoggerPreservesLevelsFormattingAndCheckedFileOutcomes(): void
    {
        $logger = new InstallerLogger($this->native, new MockClock('2026-11-01 12:34:56 UTC'));
        foreach ([1 => 'always', 2 => 'general', 3 => 'info', 4 => 'notice', 5 => 'debug'] as $level => $name) {
            $this->assertSame($name, InstallerLogger::label((string) $level));
            $this->assertSame((string) $level, InstallerLogger::normalize((string) $level));
        }
        $this->assertSame('Unknown (2.5)', InstallerLogger::label(2.5));
        $this->assertSame(1, InstallerLogger::normalize(null));
        $this->assertSame(3, InstallerLogger::normalize('invalid', 3));
        ob_start();
        $this->assertSame(1, InstallerLogger::normalize(-1));
        $this->assertSame(5, InstallerLogger::normalize(8));
        $this->assertStringContainsString('Level too low', ob_get_clean());
        self::$options = ['log_install' => '5'];
        $logger->write('schema', 'first', FILE_APPEND, 3, false, false, $this->directory);
        $this->assertSame('[2026-11-01 12:34:56] [ info    ] first' . PHP_EOL, file_get_contents($this->directory . '/log/install-schema.log'));
        $logger->write('', 'second', FILE_APPEND, 3, false, false, $this->directory);
        $this->assertFileExists($this->directory . '/log/install-general.log');
        $logger->write('schema', 'replaced', 0, 3, false, false, $this->directory);
        $this->assertStringNotContainsString('first', file_get_contents($this->directory . '/log/install-schema.log'));
        self::$options = ['log_install' => '1'];
        ob_start();
        $logger->write('disabled', 'forced', FILE_APPEND, 5, true, true, $this->directory);
        $this->assertStringContainsString('forced', ob_get_clean());
        $this->assertFileDoesNotExist($this->directory . '/log/install-disabled.log');
        $logger->record(5, 'native-hook', '', true, $this->directory);
        $this->assertSame('set_config_option', self::$calls[array_key_last(self::$calls)][0]);
        self::$options = ['log_install' => '5'];
        try {
            $logger->write('../outside', 'denied', FILE_APPEND, 1, false, false, $this->directory);
            $this->fail('Invalid section accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertFileDoesNotExist($this->directory . '/outside.log');
        }
        try {
            $logger->write('missing', 'failed', FILE_APPEND, 1, false, false, $this->directory . '/absent');
            $this->fail('Failed log write ignored.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('could not be written', $error->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        InstallerLogger::normalize('bad', []);
    }

    /** Migration status values and zero/skip/error distinctions match shipped native constants. */
    public function testMigrationDatabaseRetainsDriverStatusesAndReadSignatures(): void
    {
        $this->assertSame([DB_STATUS_ERROR, DB_STATUS_WARNING, DB_STATUS_RESTART, DB_STATUS_SUCCESS, DB_STATUS_SKIPPED], array_map(static fn(MigrationStatus $s): int => $s->value, MigrationStatus::cases()));
        $db = new InstallerMigrationDatabase($this->native);
        $this->assertSame(3, $this->quiet(fn() => $db->execute('SELECT ?', [0])));
        $this->assertSame(3, $db->execute('SELECT 1', [], false));
        self::$handlers['db_execute_prepared'] = static fn(): bool => false;
        $this->assertSame(0, $this->quiet(fn() => $db->execute('SELECT 1')));
        self::$handlers['db_execute_prepared'] = static fn(): bool => true;
        foreach (['db_fetch_cell_prepared', 'db_fetch_row_prepared', 'db_fetch_assoc_prepared'] as $reader) {
            self::$handlers[$reader] = static fn(): string => 'observed';
            $GLOBALS['database_last_error'] = 'stale-failure';
            $this->assertSame(['status' => 3, 'data' => 'observed'], $this->quiet(fn() => $db->fetch($reader, 'SELECT ?', [1])));
        }
        self::$handlers['db_error'] = static fn(): string => 'fixture-failed-read';
        $this->assertSame(0, $this->quiet(fn() => $db->fetch('db_fetch_cell_prepared', 'SELECT 1', [], false))['status']);
        unset(self::$handlers['db_error']);
        $this->expectException(\InvalidArgumentException::class);
        $db->fetch('arbitrary_function', 'SELECT 1');
    }
    /** Upgrade helpers preserve skip decisions and stop after refused index removal. */
    public function testMigrationSchemaOperationsKeepAllNativeOutcomes(): void
    {
        $db = new InstallerMigrationDatabase($this->native);
        $this->assertSame(4, $this->quiet(fn() => $db->addKey('host', 'KEY', 'idx', ['id'])));
        self::$handlers['db_index_matches'] = static fn(): int => 1;
        $this->assertSame(3, $this->quiet(fn() => $db->addKey('host', 'KEY', 'PRIMARY', 'id', 'BTREE')));
        self::$handlers['db_index_exists'] = static fn(): bool => true;
        $this->assertSame(3, $this->quiet(fn() => $db->addKey('host', 'UNIQUE KEY', 'idx', ['id'])));
        $this->assertSame(3, $this->quiet(fn() => $db->dropKey('host', 'KEY', 'PRIMARY')));
        self::$handlers['db_execute_prepared'] = static fn(): bool => false;
        $this->assertSame(0, $this->quiet(fn() => $db->addKey('host', 'KEY', 'idx', ['id'])));
        $this->assertSame(0, $this->quiet(fn() => $db->dropTable('host')));
        self::$handlers['db_execute_prepared'] = static fn(): bool => true;
        $this->assertSame(3, $this->quiet(fn() => $db->dropTable('host')));
        self::$handlers['db_table_exists'] = static fn(string $table): bool => $table !== 'destination';
        $this->assertSame(3, $this->quiet(fn() => $db->renameTable('host', 'destination')));
        $this->assertSame(4, $this->quiet(fn() => $db->renameTable('host', 'existing')));
        $this->assertSame(3, $this->quiet(fn() => $db->dropColumn('host', 'field')));
        self::$handlers['db_remove_column'] = static fn(): bool => false;
        $this->assertSame(0, $this->quiet(fn() => $db->dropColumn('host', 'field')));
        self::$handlers['db_column_exists'] = static fn(): bool => false;
        $this->assertSame(4, $this->quiet(fn() => $db->dropColumn('host', 'field')));
        $this->assertSame(3, $db->addColumn('host', ['name' => 'field'])['status']);
        self::$handlers['db_add_column'] = static fn(): bool => false;
        $this->assertSame(0, $db->addColumn('host', ['name' => 'field'])['status']);
        self::$handlers['db_column_exists'] = static fn(): bool => true;
        $this->assertSame(3, $db->addColumn('host', ['name' => 'field'])['status']);
        $this->assertSame(4, $db->addColumn('host', ['name' => 'field'], false)['status']);
        self::$handlers['db_table_exists'] = static fn(): bool => false;
        $missing = $db->addColumn('host', ['name' => 'field']);
        $this->assertSame(1, $missing['status']);
        $this->assertStringContainsString('missing', $missing['warning']);
        $this->assertSame(1, $this->quiet(fn() => \db_install_add_column('host', ['name' => 'field'])));
        $this->assertStringContainsString('missing', $GLOBALS['database_last_error']);
        $this->assertSame(4, $this->quiet(fn() => $db->dropTable('absent')));
    }

    /** A failed lookup or destination write must not delete the old setting. */
    public function testSettingRenameKeepsFailedOperationsRetryable(): void
    {
        $db = new InstallerMigrationDatabase($this->native);
        self::$handlers['db_fetch_cell_prepared'] = static fn(string $sql): string => str_contains($sql, 'COUNT') ? '0' : 'old-value';
        $this->quiet(fn() => $db->swapSetting('old', 'new'));
        $this->assertSame(['new', 'old'], array_values(array_filter(self::$calls, static fn(array $call): bool => $call[0] === 'db_execute_prepared'))[0][1][1]);
        self::$handlers['db_fetch_cell_prepared'] = static fn(string $sql): string => str_contains($sql, 'COUNT') ? '1' : 'old-value';
        $this->quiet(fn() => $db->swapSetting('old', 'new'));
        self::$handlers['db_execute_prepared'] = static fn(): bool => false;
        try {
            $this->quiet(fn() => $db->swapSetting('old', 'new'));
            $this->fail('Failed write accepted.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('replacement failed', $error->getMessage());
        }
        self::$handlers['db_error'] = static fn(): bool => true;
        try {
            $this->quiet(fn() => $db->swapSetting('old', 'new'));
            $this->fail('Failed first read accepted.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('lookup failed', $error->getMessage());
        }
        $reads = 0;
        self::$handlers['db_error'] = static function () use (&$reads): bool {
            return ++$reads > 1;
        };
        try {
            $this->quiet(fn() => $db->swapSetting('old', 'new'));
            $this->fail('Failed second read accepted.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('lookup failed', $error->getMessage());
        }
    }

    /** Progress keeps status/SQL structure while withholding bound values and checking cache writes. */
    public function testMigrationProgressRetainsVersionCacheWithoutBoundValues(): void
    {
        $progress = new InstallerMigrationProgress($this->native);
        $cache = [];
        $this->quiet(function () use ($progress, &$cache) {
            return $progress->record(3, 'UPDATE settings SET value = ?', ['private-fixture-canary'], '1.3.0', false, $cache);
        });
        $this->assertSame('UPDATE settings SET value = ?', $cache['1.3.0'][0]['sql']);
        $this->assertStringNotContainsString('private-fixture-canary', json_encode($cache));
        $file = tempnam($this->directory, 'cache-');
        $cache['file'] = $file;
        $this->quiet(function () use ($progress, &$cache) {
            return $progress->record(0, 'SELECT ?, ?', [0], '1.3.0', 'failed', $cache);
        });
        $this->assertStringContainsString('2 parameters expected, 1 provided', file_get_contents($file));
        foreach ([4, 1, 99] as $status) {
            $this->quiet(function () use ($progress, &$cache, $status) {
                return $progress->record($status, 'SELECT 1', null, '1.3.0', false, $cache);
            });
        }
        $bad = ['1.3.0' => 'not-a-list'];
        try {
            $this->quiet(function () use ($progress, &$bad) {
                return $progress->record(3, 'SELECT 1', null, '1.3.0', false, $bad);
            });
            $this->fail('Malformed cache accepted.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Invalid', $error->getMessage());
        }
        $bad = ['file' => $this->directory . '/missing'];
        try {
            $this->quiet(function () use ($progress, &$bad) {
                return $progress->record(3, 'SELECT 1', null, '1.3.0', false, $bad);
            });
            $this->fail('Missing cache accepted.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('unavailable', $error->getMessage());
        }
        $bad = ['file' => __FILE__];
        try {
            $this->quiet(function () use ($progress, &$bad) {
                return $progress->record(3, 'SELECT 1', null, '1.3.0', false, $bad);
            });
            $this->fail('Foreign cache destination accepted.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('destination', $error->getMessage());
        }
        $this->expectException(\RuntimeException::class);
        $this->quiet(function () use ($progress, &$cache) {
            return $progress->record(3, 'SELECT 1', null, 'file', false, $cache);
        });
    }

    /** Probe outcomes include failed create/drop and always close a successfully opened connection. */
    public function testDatabaseDiagnosticsPreserveBooleanStringProtocol(): void
    {
        $diagnostics = new InstallerDatabaseDiagnostics($this->native);
        $this->assertSame('{"status":"false"}', $diagnostics->connection([]));
        $connection = new \stdClass();
        self::$handlers['db_connect_real'] = static fn(): object => $connection;
        $this->assertSame('{"status":"true"}', $diagnostics->connection(['fixture-host']));
        $this->assertSame(['db_close', [$connection]], self::$calls[array_key_last(self::$calls)]);
        $this->assertTrue($diagnostics->temporaryTable());
        self::$handlers['db_execute'] = static fn(): bool => false;
        $this->assertFalse($diagnostics->temporaryTable());
        $calls = 0;
        self::$handlers['db_execute'] = static function () use (&$calls): bool {
            return ++$calls === 1;
        };
        $this->assertFalse($diagnostics->temporaryTable());
    }

    /** Secret initialization is exclusive and a competing initializer's complete value survives. */
    public function testSecretInitializationPreservesExistingAndCompetingFiles(): void
    {
        $secret = new InstallerCsrfSecret($this->native);
        $path = $this->directory . '/external-secret';
        $this->assertTrue($secret->create($path));
        $this->assertSame('disposable-generated-fixture-secret', file_get_contents($path));
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertTrue($secret->create($path));
        self::$handlers['cacti_csrf_external_path_is_safe'] = static fn(): bool => false;
        $this->assertFalse($secret->create($this->directory . '/denied'));
        unset(self::$handlers['cacti_csrf_external_path_is_safe']);
        symlink($path, $this->directory . '/alias');
        $this->assertFalse($secret->create($this->directory . '/alias'));
        $this->assertFalse($secret->create($this->directory . '/missing/secret'));
        file_put_contents($this->directory . '/empty', '');
        $this->assertFalse($secret->create($this->directory . '/empty'));
        self::$handlers['csrf_get_secret'] = function () use ($path): string {
            file_put_contents($this->directory . '/competing', 'competing-value');
            return 'discarded-own-value';
        };
        $this->assertTrue($secret->create($this->directory . '/competing'));
        $this->assertSame('competing-value', file_get_contents($this->directory . '/competing'));
        self::$handlers['csrf_get_secret'] = function () use ($path): string {
            symlink($path, $this->directory . '/raced-link');
            return 'discarded-value';
        };
        $this->assertFalse($secret->create($this->directory . '/raced-link'));
        self::$handlers['csrf_get_secret'] = static fn(): string => '';
        $this->assertFalse($secret->create($this->directory . '/invalid'));
        $this->assertSame([], glob($this->directory . '/.installer-secret-*'));
    }

    /** Release CSV values remain bound data, including quotes, and failures do not become success. */
    public function testColorImportUsesBoundValuesAndDistinguishesMissingOrMalformedCatalogs(): void
    {
        $import = new InstallerColorImport($this->directory, $this->native);
        $this->assertFalse($import->import());
        mkdir($this->directory . '/install', 0700);
        $file = $this->directory . '/install/colors.csv';
        file_put_contents($file, "1,ABCDEF,Owner's color\n2,012345,Other\n");
        self::$handlers['db_fetch_cell_prepared'] = static fn(string $sql, array $args): mixed => $args[0] === 'ABCDEF' ? 'ABCDEF' : false;
        $this->assertTrue($import->import());
        $writes = array_values(array_filter(self::$calls, static fn(array $call): bool => $call[0] === 'db_execute_prepared'));
        $this->assertSame(["Owner's color", 'ABCDEF'], $writes[0][1][1]);
        $this->assertStringNotContainsString("Owner's", $writes[0][1][0]);
        self::$handlers['db_execute_prepared'] = static fn(): bool => false;
        try {
            $import->import();
            $this->fail('Color write failure ignored.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('stored', $error->getMessage());
        }
        self::$handlers['db_error'] = static fn(): bool => true;
        try {
            $import->import();
            $this->fail('Color read failure ignored.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('lookup failed', $error->getMessage());
        }
        unset(self::$handlers['db_error']);
        file_put_contents($file, 'invalid');
        $this->expectException(\RuntimeException::class);
        $import->import();
    }

    /** Replication results preserve skipped, stale, failed and confirmed collectors independently. */
    public function testCollectorSynchronizationPreservesPartialOutcomes(): void
    {
        self::$options['poller_interval'] = '60';
        $sync = new InstallerCollectorSynchronization($this->native);
        $this->assertSame(['success' => [], 'failed' => [], 'skipped' => [], 'timeout' => [], 'total' => 0], $sync->run());
        self::$handlers['db_fetch_assoc'] = static fn(): array => [
            ['id' => '2', 'status' => '0', 'gap' => '1'], ['id' => '3', 'status' => '2', 'gap' => '121'],
            ['id' => '4', 'status' => '2', 'gap' => '2'], ['id' => '5', 'status' => '2', 'gap' => '2'],
        ];
        self::$handlers['replicate_out'] = static fn(string $id): bool => $id === '4';
        $this->assertSame(['success' => ['4'], 'failed' => ['5'], 'skipped' => ['2'], 'timeout' => ['3'], 'total' => 4], $sync->run());
        foreach ([false, [null], [['id' => '2', 'status' => 'bad', 'gap' => '1']]] as $bad) {
            self::$handlers['db_fetch_assoc'] = static fn(): mixed => $bad;
            try {
                $sync->run();
                $this->fail('Malformed collector read accepted.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('collector', strtolower($error->getMessage()));
            }
        }
        self::$options['poller_interval'] = 'invalid';
        $this->expectException(\RuntimeException::class);
        $sync->run();
    }

    /** Only named release-owned automation rules are repaired; user changes are preserved. */
    public function testAutomationRepairKeepsReleaseHashesAndSkipsUserRenamedRules(): void
    {
        $repair = new InstallerAutomationRepair($this->native);
        $repair->repair();
        $this->assertCount(0, array_filter(self::$calls, static fn(array $call): bool => $call[0] === 'db_execute_prepared'));
        self::$handlers['db_fetch_row_prepared'] = static fn(string $sql, array $args): array => $args[0] === 1 ? ['id' => 1] : [];
        self::$handlers['db_fetch_cell_prepared'] = static fn(string $sql): string => str_contains($sql, 'snmp_query_graph') ? '18' : '8';
        $repair->repair();
        $writes = array_values(array_filter(self::$calls, static fn(array $call): bool => $call[0] === 'db_execute_prepared'));
        $this->assertCount(1, $writes);
        $this->assertSame(['8', '18', 1], $writes[0][1][1]);
    }

    /** Remote registration selects its explicit connection and preserves adjacent configuration text. */
    public function testCollectorConfigurationUsesRemoteSessionAndAtomicLiteralAssignment(): void
    {
        mkdir($this->directory . '/include', 0700);
        $file = $this->directory . '/include/config.php';
        $source = '<?php // $poller_id in a comment' . "\n" . '$poller_id = 1;' . "\n" . '$retained = "literal $poller_id";' . "\n";
        file_put_contents($file, $source);
        chmod($file, 0600);
        $connection = new \stdClass();
        self::$handlers['db_connect_real'] = static fn(): object => $connection;
        self::$handlers['db_fetch_cell_prepared'] = function (string $sql, array $args, string $column, bool $log, object $db) use ($connection): string {
            $this->assertSame('', $column);
            $this->assertSame($connection, $db);
            return '7';
        };
        $configuration = new InstallerCollectorConfiguration($this->directory, $this->native);
        $this->assertSame('', $configuration->update(['remote-arguments'], []));
        $this->assertSame(str_replace('$poller_id = 1;', '$poller_id = 7;', $source), file_get_contents($file));
        $this->assertSame(0600, fileperms($file) & 0777);
        $this->assertSame(['db_close', [$connection]], self::$calls[array_key_last(self::$calls)]);
        $fallback = new InstallerCollectorConfiguration($this->directory, new NativeInstallerFunctions(static fn(): bool => false));
        $this->assertSame('', $fallback->update([], []));
        file_put_contents($file, '<?php $poller_id = 1; $poller_id; // retained read');
        $this->assertSame('', $configuration->update([], []));
        $this->assertSame('<?php $poller_id = 7; $poller_id; // retained read', file_get_contents($file));
        self::$handlers['db_fetch_cell_prepared'] = static fn(): bool => false;
        self::$handlers['sql_save'] = static fn(): int => 9;
        $this->assertSame('', $configuration->update([], ['dbdefault' => 'local']));
        self::$handlers['sql_save'] = static fn(): int => 0;
        $this->assertStringContainsString('poller id', $configuration->update([], []));
        self::$handlers['db_error'] = static fn(): string => 'failed';
        $this->assertStringContainsString('poller id', $configuration->update([], []));
        unset(self::$handlers['db_error']);
        self::$handlers['db_connect_real'] = static fn(): bool => false;
        $this->assertSame('Failed to connect database', $configuration->update([], []));
        self::$handlers['db_connect_real'] = static fn(): object => $connection;
        self::$handlers['db_fetch_cell_prepared'] = static fn(): string => '7';
        foreach (['<?php $other = 1;', '<?php $poller_id=1; $poller_id=2;', '<?php invalid syntax'] as $malformed) {
            file_put_contents($file, $malformed);
            $this->assertSame('Failed to update configuration file', $configuration->update([], []));
            $this->assertSame($malformed, file_get_contents($file));
        }
        file_put_contents($file, '');
        $this->assertSame('Failed to read configuration file', $configuration->update([], []));
        unlink($file);
        $this->assertSame('Configuration file is not writable', $configuration->update([], []));
    }

    /** Framework filesystem failures preserve the initialization result and clean only owned temporaries. */
    public function testSecretFilesystemFailuresAreControlledAndCleanupIsConfirmed(): void
    {
        $files = $this->createMock(Filesystem::class);
        $files->method('tempnam')->willThrowException(new \Symfony\Component\Filesystem\Exception\IOException('fixture allocation refused'));
        $this->assertFalse((new InstallerCsrfSecret($this->native, $files))->create($this->directory . '/failed'));
        $files = $this->createMock(Filesystem::class);
        $temporary = $this->directory . '/owned-temporary';
        file_put_contents($temporary, '');
        $files->method('tempnam')->willReturn($temporary);
        $files->method('dumpFile')->willReturnCallback(static function (string $file, string $bytes): void {
            file_put_contents($file, $bytes);
        });
        $files->method('remove')->willThrowException(new \Symfony\Component\Filesystem\Exception\IOException('fixture cleanup refused'));
        $this->expectException(\Symfony\Component\Filesystem\Exception\IOException::class);
        (new InstallerCsrfSecret($this->native, $files))->create($this->directory . '/published');
    }

    /** An unconfirmed database acknowledgement is a failed synchronization outcome after remote publication. */
    public function testReplicationAcknowledgementFailureDoesNotPublishOverallSuccess(): void
    {
        self::$options['poller_interval'] = '60';
        self::$handlers['db_fetch_assoc'] = static fn(): array => [['id' => '4', 'status' => '2', 'gap' => '1']];
        self::$handlers['replicate_out'] = static fn(): bool => true;
        self::$handlers['db_execute_prepared'] = static fn(): bool => false;
        $result = (new InstallerCollectorSynchronization($this->native))->run();
        $this->assertSame([], $result['success']);
        $this->assertSame(['4'], $result['failed']);
    }

    /** Automation failures stop before publishing broken template references or a successful result. */
    public function testAutomationFailuresNeverBecomeAConfirmedRepair(): void
    {
        $repair = new InstallerAutomationRepair($this->native);
        self::$handlers['db_error'] = static fn(): string => 'failed';
        try {
            $repair->repair();
            $this->fail('Failed rule lookup accepted.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('lookup failed', $error->getMessage());
        }
        unset(self::$handlers['db_error']);
        self::$handlers['db_fetch_row_prepared'] = static fn(): array => ['id' => 1];
        try {
            $repair->repair();
            $this->fail('Missing template references accepted.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('references', $error->getMessage());
        }
        self::$handlers['db_fetch_cell_prepared'] = static fn(): int => 8;
        self::$handlers['db_execute_prepared'] = static fn(): bool => false;
        $this->expectException(\RuntimeException::class);
        $repair->repair();
    }

    /** Configuration write and verification failures retain explicit failure outcomes and close the remote session. */
    public function testCollectorConfigurationFailureStagesRetainTheOriginalFile(): void
    {
        mkdir($this->directory . '/include', 0700);
        $file = $this->directory . '/include/config.php';
        $source = '<?php $poller_id = (int) (1);';
        file_put_contents($file, $source);
        $connection = new \stdClass();
        self::$handlers['db_connect_real'] = static fn(): object => $connection;
        self::$handlers['db_fetch_cell_prepared'] = static fn(): int => 7;
        $files = $this->createMock(Filesystem::class);
        $files->method('dumpFile')->willThrowException(new \Symfony\Component\Filesystem\Exception\IOException('fixture replacement refused'));
        $configuration = new InstallerCollectorConfiguration($this->directory, $this->native, $files);
        $this->assertSame('Failed to update configuration file', $configuration->update([], []));
        $this->assertSame($source, file_get_contents($file));
        $files = $this->createMock(Filesystem::class);
        $configuration = new InstallerCollectorConfiguration($this->directory, $this->native, $files);
        $this->assertSame('Failed to confirm configuration file', $configuration->update([], []));
        self::$handlers['db_fetch_cell_prepared'] = static function () use ($file): int {
            file_put_contents($file, '<?php $poller_id = 2; // changed');
            return 7;
        };
        $this->assertSame('Configuration file changed during installation', $configuration->update([], []));
        $this->assertSame('<?php $poller_id = 2; // changed', file_get_contents($file));
        self::$handlers['db_fetch_cell_prepared'] = static fn(): int => 7;
        file_put_contents($file, '<?php function local_only() { $poller_id = 3; }');
        $this->assertSame('Failed to update configuration file', $configuration->update([], []));
        $this->assertSame(['db_close', [$connection]], self::$calls[array_key_last(self::$calls)]);
    }

    /** Real filesystem permission refusal must remain an error for log and migration-cache appends. */
    public function testAppendPermissionFailuresNeverBecomeSuccessfulDiagnostics(): void
    {
        $path = $this->directory . '/log/install-refused.log';
        $cachePath = $this->directory . '/migration-cache';
        file_put_contents($path, 'original');
        file_put_contents($cachePath, 'original');
        chmod($this->directory, 0755);
        chmod($this->directory . '/log', 0755);
        chmod($path, 0400);
        chmod($cachePath, 0400);
        self::$options['log_install'] = '5';
        $identity = posix_geteuid();
        if ($identity === 0) {
            $this->assertTrue(posix_seteuid(65534));
        }
        try {
            try {
                (new InstallerLogger($this->native))->write('refused', 'failed', FILE_APPEND, 1, false, false, $this->directory);
                $this->fail('Log append refusal ignored.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('could not be written', $error->getMessage());
            }
            self::$options['log_install'] = '1';
            $cache = ['file' => $cachePath];
            try {
                $this->quiet(function () use (&$cache): void {
                    (new InstallerMigrationProgress($this->native))->record(0, 'SELECT 1', null, '1.3.0', false, $cache);
                });
                $this->fail('Cache append refusal ignored.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('could not be written', $error->getMessage());
            }
        } finally {
            if ($identity === 0) {
                $this->assertTrue(posix_seteuid(0));
            }
            chmod($this->directory, 0700);
            chmod($this->directory . '/log', 0700);
        }
        $this->assertSame('original', file_get_contents($path));
        $this->assertSame('original', file_get_contents($cachePath));
    }

    /** PHP stream errors must not turn an unread or prematurely stopped CSV into an empty successful import. */
    public function testColorImportRejectsOpenAndLateReadFailures(): void
    {
        stream_wrapper_register('installerfail', InstallerReadFailureStream::class);
        try {
            foreach ([false, true] as $open) {
                InstallerReadFailureStream::$open = $open;
                clearstatcache();
                try {
                    (new InstallerColorImport('installerfail://release', $this->native))->import();
                    $this->fail('Failed CSV read accepted.');
                } catch (\RuntimeException $error) {
                    $this->assertStringContainsString($open ? 'completely read' : 'could not be read', $error->getMessage());
                }
            }
        } finally {
            stream_wrapper_unregister('installerfail');
        }
    }

    /** The native backend selects the injected providers instead of falling back to procedural catalog calls. */
    public function testNativeBackendUsesTheInjectedCatalogServices(): void
    {
        $root = dirname(__DIR__, 2);
        self::$handlers['get_cacti_base_tables'] = static fn(): array => [];
        $backend = new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerBackend(
            $root,
            $this->createMock(\Kadupul\IdentityAccess\Contract\ConsoleOperator::class),
            $this->native,
            templateCatalog: new \Kadupul\Platform\Infrastructure\Legacy\InstallerTemplateCatalog($root),
            tableCatalog: new \Kadupul\Platform\Infrastructure\Legacy\InstallerTableCatalog($this->native),
        );
        $reader = new \ReflectionMethod($backend, 'nativeList');
        $this->assertCount(30, $reader->invoke($backend, 'install_setup_get_templates'));
        $this->assertSame([], $reader->invoke($backend, 'install_setup_get_tables'));
        $this->assertSame([], array_filter(self::$calls, static fn(array $call): bool => in_array($call[0], ['install_setup_get_templates', 'install_setup_get_tables'], true)));
    }

}
