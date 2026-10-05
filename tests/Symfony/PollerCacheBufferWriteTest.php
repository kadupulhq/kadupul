<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Infrastructure\Legacy\PollerCacheBufferWrite;
use Kadupul\Inventory\Infrastructure\Legacy\QueuedCollectorPurge;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/src/Platform/Infrastructure/Legacy/LegacyReferenceWriteTransaction.php';
require_once dirname(__DIR__, 2) . '/src/Platform/Contract/ReferenceWriteTransactionRunner.php';
require_once dirname(__DIR__, 2) . '/src/Platform/Infrastructure/Legacy/NativeReferenceWriteTransactionRunner.php';
require_once dirname(__DIR__, 2) . '/src/Inventory/Infrastructure/Legacy/PollerCacheBufferWrite.php';
require_once dirname(__DIR__, 2) . '/src/Inventory/Infrastructure/Legacy/QueuedCollectorPurge.php';
require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

/** Fixed-code business/failure contracts, without devices or interleaved writers. */
final class PollerCacheBufferWriteTest extends TestCase
{
    private static ?PDO $administrator = null;
    private static array $schemas = [];
    private static array $dsns = [];
    private static string $user;
    private static string $password;
    private PDO $primary;
    private PDO $remote;
    private string $prefix;
    private string $suffix;
    private array $previousGlobals = [];

    public static function setUpBeforeClass(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!$dsn) {
            return;
        }
        self::$user = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root');
        self::$password = getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '');
        self::$administrator = new PDO($dsn, self::$user, self::$password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        try {
            foreach (['primary', 'remote'] as $role) {
                $schema = 'buffer_guard_' . bin2hex(random_bytes(10));
                self::$administrator->exec('CREATE DATABASE `' . $schema . '`');
                self::$schemas[] = $schema;
                self::$dsns[$role] = preg_replace('/dbname=[^;]+/', 'dbname=' . $schema, $dsn);
            }
        } catch (\Throwable $error) {
            self::tearDownAfterClass();
            throw $error;
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$schemas as $schema) {
            self::$administrator->exec('DROP DATABASE `' . $schema . '`');
        }
        self::$schemas = [];
        self::$dsns = [];
        self::$administrator = null;
    }

    protected function setUp(): void
    {
        foreach (['config', 'database_sessions', 'database_hostname', 'database_port', 'database_default', 'database_total_queries', '_SESSION'] as $name) {
            $this->previousGlobals[$name] = [
                'exists' => array_key_exists($name, $GLOBALS),
                'value' => $GLOBALS[$name] ?? null,
            ];
        }
        if (count(self::$dsns) !== 2) {
            self::markTestSkipped('Owned MySQL/MariaDB schemas required.');
        }
        $schema = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        foreach (self::$dsns as $role => $dsn) {
            $this->$role = new BufferReceiptPdo($dsn, self::$user, self::$password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $database = $this->$role;
            // Legacy install DDL contains historical zero timestamp defaults.
            $database->exec("SET SESSION sql_mode = ''");
            foreach (['host', 'data_local', 'poller_item', 'settings'] as $table) {
                $database->exec('DROP TABLE IF EXISTS `' . $table . '`');
                self::assertSame(1, preg_match('/CREATE TABLE `?' . $table . '`? \((.*?)\) ENGINE=.*?;/s', $schema, $match));
                $database->exec($match[0]);
            }
            $database->exec("INSERT INTO host(id,poller_id,hostname) VALUES(7,3,'owned')");
            $database->exec('INSERT INTO data_local(id,host_id) VALUES(11,7),(12,0),(77,0)');
        }
        $source = file_get_contents(dirname(__DIR__, 2) . '/lib/utility.php');
        self::assertIsString($source);
        $function = \test_php_function_source($source, 'poller_update_poller_cache_from_buffer');
        $tokens = token_get_all('<?php ' . $function);
        $declarations = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_VARIABLE
                || !in_array($token[1], ['$sql_prefix', '$sql_suffix'], true)
                || isset($declarations[$token[1]])) {
                continue;
            }
            $declaration = '';
            for ($cursor = $index; isset($tokens[$cursor]); $cursor++) {
                $part = $tokens[$cursor];
                $declaration .= is_array($part) ? $part[1] : $part;
                if ($part === ';') {
                    break;
                }
            }
            self::assertStringContainsString('=', $declaration);
            $declarations[$token[1]] = $declaration;
        }
        self::assertCount(2, $declarations);
        // Execute actual unchanged SQL prefix/suffix declarations, not a mirror.
        $sql_prefix = $sql_suffix = '';
        eval(implode("\n", $declarations));
        $this->prefix = $sql_prefix;
        $this->suffix = $sql_suffix;
    }

    protected function tearDown(): void
    {
        foreach ($this->previousGlobals as $name => $previous) {
            if ($previous['exists']) {
                $GLOBALS[$name] = $previous['value'];
            } else {
                unset($GLOBALS[$name]);
            }
        }
        $this->previousGlobals = [];
    }

    private function tuple(int $id = 11, int $poller = 3, int $host = 7, string $payload = 'plugin ! scalar'): string
    {
        $values = [$id, $poller, $host, 1, 'owned', '', 0, 500, '', '', '', '', '', '', '', 161, 'traffic', '/owned/fixture.rrd', 1, 300, 0, $payload, '', '', 1];
        return '(' . implode(', ', array_map(fn($value): string => is_int($value) ? (string) $value : $this->primary->quote($value), $values)) . ')';
    }

    private function apply(array $ids = [11], ?array $items = null, int $poller = 3, ?PDO $remote = null, ?PDO $primary = null): ?int
    {
        return (new PollerCacheBufferWrite(new \Kadupul\Platform\Infrastructure\Legacy\NativeReferenceWriteTransactionRunner()))->write($primary ?? $this->primary, $ids, $items ?? [$this->tuple()], $poller, $this->prefix, $this->suffix, fn(): PDO => $remote ?? $this->remote, static fn(): bool => true);
    }

    private function rows(PDO $database): array
    {
        return $database->query('SELECT local_data_id,poller_id,host_id,arg1,present FROM poller_item ORDER BY local_data_id,rrd_name')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function refused(callable $operation): void
    {
        $error = null;
        try {
            $operation();
        } catch (RuntimeException $caught) {
            $error = $caught;
        }
        self::assertInstanceOf(RuntimeException::class, $error);
    }

    public function testHealthyCurrentOwnerPreservesPayloadAndDefaults(): void
    {
        self::assertIsInt($this->apply());
        self::assertSame($this->rows($this->primary), $this->rows($this->remote));
        self::assertSame('plugin ! scalar', $this->rows($this->primary)[0]['arg1']);
        self::assertSame(1, (int) $this->rows($this->primary)[0]['present']);
        self::assertFalse($this->primary->inTransaction());
        self::assertFalse($this->remote->inTransaction());
    }

    public function testActualCommonWrapperUsesCapturedPrimaryAndDefaultHostZero(): void
    {
        if (!function_exists('poller_update_poller_cache_from_buffer')) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/lib/utility.php');
            self::assertIsString($source);
            eval('namespace { ' . \test_php_function_source($source, 'poller_update_poller_cache_from_buffer') . ' }');
        }
        $GLOBALS['config'] = ['poller_id' => 1, 'is_web' => false, 'config_options_array' => 'malformed prior cache'];
        $GLOBALS['database_hostname'] = 'owned';
        $GLOBALS['database_port'] = 1;
        $GLOBALS['database_default'] = 'fixture';
        $GLOBALS['database_sessions'] = ['owned:1:fixture' => $this->primary];
        $items = [$this->tuple(12, 1, 0, 'actual wrapper')];
        \poller_update_poller_cache_from_buffer([12], $items);
        self::assertSame('actual wrapper', $this->rows($this->primary)[0]['arg1']);
        self::assertSame([], $this->rows($this->remote));
        self::assertIsInt($GLOBALS['config']['config_options_array']['time_last_change_poller_item']);
        $GLOBALS['config']['is_web'] = true;
        $_SESSION['sess_config_array'] = 'malformed prior cache';
        \poller_update_poller_cache_from_buffer([12], $items);
        self::assertIsInt($_SESSION['sess_config_array']['time_last_change_poller_item']);
        self::assertSame($this->primary, QueuedCollectorPurge::primary(['poller_id' => 3, 'connection' => 'online'], [], 'unused', $this->primary));
        $this->refused(fn() => QueuedCollectorPurge::primary(['poller_id' => 3, 'connection' => 'offline'], [], 'unused', $this->remote));
    }

    public function testDeletedHostAndMalformedPluginIdentityRefuse(): void
    {
        $this->primary->exec("UPDATE host SET deleted='on' WHERE id=7");
        $this->refused(fn() => $this->apply());
        $this->refused(fn() => $this->apply([11], ['not a tuple']));
        self::assertSame([], $this->rows($this->primary));
        self::assertSame([], $this->rows($this->remote));
    }

    public function testActualDisabledAndInvalidSnmpProducerOmissionsAllowCleanup(): void
    {
        $base = dirname(__DIR__, 2);
        require_once $base . '/lib/database.php';
        require_once $base . '/lib/api_poller.php';
        if (!defined('POLLER_ACTION_SNMP')) {
            require $base . '/include/global_constants.php';
        }
        foreach (['cacti_sizeof', 'cacti_count', 'clean_up_lines'] as $function) {
            if (!function_exists($function)) {
                $source = file_get_contents($base . '/lib/functions.php');
                self::assertIsString($source);
                eval('namespace { ' . \test_php_function_source($source, $function) . ' }');
            }
        }
        $GLOBALS['database_hostname'] = 'owned';
        $GLOBALS['database_port'] = 1;
        $GLOBALS['database_default'] = 'fixture';
        $GLOBALS['database_sessions'] = ['owned:1:fixture' => $this->primary];
        $GLOBALS['database_total_queries'] = 0;
        $GLOBALS['config'] = ['is_web' => false, 'poller_id' => 1];
        $this->apply();
        $this->primary->exec("INSERT INTO host(id,poller_id,disabled) VALUES(8,3,'on'),(9,3,'')");
        $this->primary->exec('UPDATE host SET snmp_version=0 WHERE id=9');
        $disabled = \api_poller_cache_item_add(8, [], 11, 300, POLLER_ACTION_SNMP, 'traffic', 1);
        $invalid = \api_poller_cache_item_add(9, [], 11, 300, POLLER_ACTION_SNMP, 'traffic', 1);
        self::assertNull($disabled);
        self::assertNull($invalid);
        self::assertIsInt($this->apply([11], [$disabled, $invalid, false, '']));
        self::assertSame([], $this->rows($this->primary));
        self::assertSame([], $this->rows($this->remote));
    }

    public function testHostZeroUsesDefaultPrimaryAndDoesNotConnectRemote(): void
    {
        $calls = 0;
        $result = (new PollerCacheBufferWrite(new \Kadupul\Platform\Infrastructure\Legacy\NativeReferenceWriteTransactionRunner()))->write($this->primary, [12], [$this->tuple(12, 1, 0)], 1, $this->prefix, $this->suffix, function () use (&$calls): bool {
            $calls++;
            return false;
        }, static fn(): bool => true);
        self::assertIsInt($result);
        self::assertSame(0, $calls);
        self::assertSame(0, (int) $this->rows($this->primary)[0]['host_id']);
    }

    public function testEmptyItemsStillCleanSelectedCache(): void
    {
        $this->apply();
        self::assertIsInt($this->apply([11], []));
        self::assertSame([], $this->rows($this->primary));
        self::assertSame([], $this->rows($this->remote));
    }

    public function testNoSelectionPreservesTimestampWithoutPollingWrites(): void
    {
        self::assertIsInt($this->apply([], []));
        self::assertSame('1', (string) $this->primary->query('SELECT COUNT(*) FROM settings')->fetchColumn());
        self::assertSame([], $this->rows($this->primary));
        self::assertSame([], $this->rows($this->remote));
    }

    public function testBatchKeepsEveryRawPayloadAcrossPackets(): void
    {
        $items = [];
        for ($i = 0; $i < 6; $i++) {
            $items[] = $this->tuple(11, 3, 7, str_repeat('x', 55000) . $i);
        }
        self::assertIsInt($this->apply([11], $items));
        self::assertSame(str_repeat('x', 55000) . '5', $this->rows($this->primary)[0]['arg1']);
        self::assertSame($this->rows($this->primary), $this->rows($this->remote));
    }

    public function testUnavailableRemoteKeepsPrimaryAndOneWarning(): void
    {
        $warnings = 0;
        $result = (new PollerCacheBufferWrite(new \Kadupul\Platform\Infrastructure\Legacy\NativeReferenceWriteTransactionRunner()))->write($this->primary, [11], [$this->tuple()], 3, $this->prefix, $this->suffix, static fn(): bool => false, function () use (&$warnings): void {
            $warnings++;
        });
        self::assertIsInt($result);
        self::assertSame(1, $warnings);
        self::assertCount(1, $this->rows($this->primary));
        self::assertSame([], $this->rows($this->remote));
    }

    public function testMismatchedOwnerRefusesBeforeAnyMutation(): void
    {
        $this->primary->exec('UPDATE host SET poller_id=1 WHERE id=7');
        $this->refused(fn() => $this->apply());
        self::assertSame([], $this->rows($this->primary));
        self::assertSame([], $this->rows($this->remote));
    }

    public function testMissingHostAndSourceRefuseBeforeCleanup(): void
    {
        $this->primary->exec('DELETE FROM host WHERE id=7');
        $this->refused(fn() => $this->apply([11], []));
        $this->refused(fn() => $this->apply([999], []));
        self::assertSame([], $this->rows($this->primary));
    }

    public function testCallerSavepointSuccessKeepsUnrelatedWorkPending(): void
    {
        $this->primary->beginTransaction();
        $this->remote->beginTransaction();
        $this->primary->exec("INSERT INTO settings VALUES('caller','pending')");
        $this->apply();
        self::assertTrue($this->primary->inTransaction());
        self::assertTrue($this->remote->inTransaction());
        self::assertSame('pending', $this->primary->query("SELECT value FROM settings WHERE name='caller'")->fetchColumn());
        $this->primary->rollBack();
        $this->remote->rollBack();
        self::assertSame([], $this->rows($this->primary));
        self::assertSame([], $this->rows($this->remote));
    }

    public function testCallerFailureRollsBackOnlyOperation(): void
    {
        $this->primary->beginTransaction();
        $this->primary->exec("INSERT INTO settings VALUES('caller','pending')");
        $this->remote->exec("CREATE TRIGGER refuse_cache BEFORE INSERT ON poller_item FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture refusal'");
        $this->refused(fn() => $this->apply());
        self::assertTrue($this->primary->inTransaction());
        self::assertSame('pending', $this->primary->query("SELECT value FROM settings WHERE name='caller'")->fetchColumn());
        self::assertSame([], $this->rows($this->primary));
        $this->primary->rollBack();
    }

    public function testPrepareExecuteFetchAndWriteFailureRefuse(): void
    {
        foreach (['prepare', 'execute', 'fetch', 'write'] as $fault) {
            $this->primary->fault = $fault;
            $this->refused(fn() => $this->apply());
            $this->primary->fault = null;
            self::assertSame([], $this->rows($this->primary));
            self::assertSame([], $this->rows($this->remote));
            self::assertFalse($this->primary->inTransaction());
        }
    }

    public function testCommitFailureHasExplicitPartialOutcome(): void
    {
        $this->primary->fault = 'commit';
        $this->refused(fn() => $this->apply());
        self::assertFalse($this->primary->inTransaction());
        self::assertSame([], $this->rows($this->primary));
        // The other server already committed; never claim distributed rollback.
        self::assertCount(1, $this->rows($this->remote));
    }

    public function testStoredOwnershipChangesRefuseAndRollBackBothUnits(): void
    {
        foreach ([$this->primary, $this->remote] as $database) {
            $database->exec('CREATE TRIGGER changed_cache_owner BEFORE INSERT ON poller_item FOR EACH ROW SET NEW.host_id=8');
            $this->refused(fn() => $this->apply());
            self::assertSame([], $this->rows($this->primary));
            self::assertSame([], $this->rows($this->remote));
            self::assertFalse($this->primary->inTransaction());
            self::assertFalse($this->remote->inTransaction());
            $database->exec('DROP TRIGGER changed_cache_owner');
        }
    }

    public function testReadbackPreservesOtherPollersAndRecordsOutsideCleanup(): void
    {
        foreach ([$this->primary, $this->remote] as $database) {
            $database->exec("INSERT INTO poller_item(local_data_id,poller_id,host_id,rrd_name,arg1,present) VALUES(11,2,99,'historical','retain',0)");
        }
        self::assertIsInt($this->apply([], [$this->tuple()]));
        self::assertSame($this->rows($this->primary), $this->rows($this->remote));
        self::assertCount(2, $this->rows($this->primary));
        self::assertSame('retain', $this->rows($this->primary)[0]['arg1']);
        self::assertSame(0, (int) $this->rows($this->primary)[0]['present']);
        self::assertSame('plugin ! scalar', $this->rows($this->primary)[1]['arg1']);
    }

    public function testChangedTimestampRefusesWithoutClaimingRemoteRollback(): void
    {
        $this->primary->exec("CREATE TRIGGER changed_cache_timestamp BEFORE INSERT ON settings FOR EACH ROW SET NEW.value=IF(NEW.name='time_last_change_poller_item','changed',NEW.value)");
        $this->refused(fn() => $this->apply());
        self::assertSame([], $this->rows($this->primary));
        self::assertSame(0, (int) $this->primary->query('SELECT COUNT(*) FROM settings')->fetchColumn());
        self::assertCount(1, $this->rows($this->remote));
        self::assertFalse($this->primary->inTransaction());
    }

    public function testCleanupReadbackRefusesRetainedSelectedRows(): void
    {
        $this->apply();
        $before = $this->rows($this->primary);
        $this->primary->exec('CREATE TRIGGER retain_cache_marker BEFORE UPDATE ON poller_item FOR EACH ROW SET NEW.present=OLD.present');
        $this->refused(fn() => $this->apply([11], []));
        self::assertSame($before, $this->rows($this->primary));
        self::assertSame($before, $this->rows($this->remote));
        self::assertFalse($this->primary->inTransaction());
        self::assertFalse($this->remote->inTransaction());
    }
}

final class BufferReceiptPdo extends PDO
{
    public ?string $fault = null;

    public function __construct(string $dsn, string $user, string $password, array $options)
    {
        parent::__construct($dsn, $user, $password, $options);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [BufferReceiptStatement::class, [$this]]);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->fault === 'prepare' && str_starts_with($query, 'SELECT id, host_id')) {
            return false;
        }
        return parent::prepare($query, $options);
    }

    public function commit(): bool
    {
        return $this->fault === 'commit' ? false : parent::commit();
    }
}

final class BufferReceiptStatement extends PDOStatement
{
    private bool $failedRead = false;

    protected function __construct(private BufferReceiptPdo $database) {}

    public function execute(?array $params = null): bool
    {
        if ($this->database->fault === 'execute' && str_starts_with($this->queryString, 'SELECT id, host_id')
            || $this->database->fault === 'write' && str_starts_with($this->queryString, 'INSERT INTO poller_item')) {
            return false;
        }
        return parent::execute($params);
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = parent::fetchAll($mode, ...$args);
        $this->failedRead = $this->database->fault === 'fetch' && str_starts_with($this->queryString, 'SELECT id, host_id');
        return $rows;
    }

    public function errorCode(): ?string
    {
        return $this->failedRead ? 'HY000' : parent::errorCode();
    }
}
