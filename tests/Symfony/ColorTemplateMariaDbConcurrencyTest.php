<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\ColorTemplates\Application\Port\ColorTemplateAccess;
use Kadupul\ColorTemplates\Infrastructure\Legacy\LegacyColorTemplateAccess;
use Kadupul\ColorTemplates\Infrastructure\Legacy\LegacyColorTemplateStore;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Tests\Fixtures\RealMariaDb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColorTemplateMariaDbConcurrencyTest extends TestCase
{
    use RealMariaDb;

    #[DataProvider('references')]
    public function testDeletionLocksBothDependencyGaps(string $table, bool $populated): void
    {
        $this->scenario(function (\PDO $owner, \PDO $writer) use ($table, $populated): void {
            $column = $table === 'aggregate_graph_templates_item' ? 'aggregate_template_id' : 'aggregate_graph_id';
            if ($populated) {
                $owner->exec("INSERT INTO $table ($column,graph_templates_item_id,color_template,item_skip,item_total) VALUES (99,99,99,'','')");
            }
            $attempted = false;
            $probe = static function (string $sql) use ($writer, $table, $column, &$attempted): void {
                if (str_starts_with($sql, "SELECT color_template FROM $table ")) {
                    $attempted = true;
                    self::blocked($writer, "INSERT INTO $table ($column,graph_templates_item_id,color_template,item_skip,item_total) VALUES (1,1,1,'','')");
                }
            };
            $owner->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [ColorTemplateRaceStatement::class, [$probe]]);
            $this->store($owner)->delete(42, [1]);
            self::assertTrue($attempted);
            self::assertSame(0, (int) $owner->query('SELECT COUNT(*) FROM color_templates')->fetchColumn());
            self::assertSame(0, (int) $owner->query("SELECT COUNT(*) FROM $table WHERE color_template=1")->fetchColumn());
        });
    }

    #[DataProvider('waitingWriters')]
    public function testActualAggregateWriterRechecksParentAfterDeletionCommits(string $table, string $isolation): void
    {
        $this->scenario(function (\PDO $owner, \PDO $observer) use ($table, $isolation): void {
            $column = $table === 'aggregate_graph_templates_item' ? 'aggregate_template_id' : 'aggregate_graph_id';
            $owner->exec("INSERT INTO $table ($column,graph_templates_item_id,color_template,item_skip,item_total) VALUES (1,99,0,'','')");
            $schema = $owner->query('SELECT DATABASE()')->fetchColumn();
            $process = null;
            $pipes = [];
            $waiting = false;
            $probe = static function (string $sql) use ($schema, $table, $isolation, $observer, &$process, &$pipes, &$waiting): void {
                if ($process !== null || !str_starts_with($sql, 'SELECT color_template_id,name FROM color_templates WHERE color_template_id IN')) {
                    return;
                }
                $process = proc_open(
                    [PHP_BINARY, __DIR__ . '/color_template_writer_probe.php', $schema, $table, $isolation],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    dirname(__DIR__, 2)
                );
                self::assertIsResource($process);
                $writerId = (int) fgets($pipes[1]);
                self::assertGreaterThan(0, $writerId);
                $deadline = microtime(true) + 5;
                do {
                    $pending = $observer->query('SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX t ON t.trx_id=w.requesting_trx_id WHERE t.trx_mysql_thread_id=' . $writerId)->fetchColumn();
                    // MariaDB can report a newly created table's blocked
                    // current read as Statistics before exposing INNODB_TRX.
                    // Require the real parent read to remain pending across
                    // observations; do not substitute a cancelled lock timeout.
                    $active = $observer->query('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=' . $writerId)->fetchColumn();
                    if ((int) $pending > 0 || (is_string($active) && str_starts_with($active, 'SELECT color_template_id FROM color_templates WHERE color_template_id='))) {
                        usleep(100000);
                        $stillActive = $observer->query('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=' . $writerId)->fetchColumn();
                        self::assertIsString($stillActive);
                        self::assertStringStartsWith('SELECT color_template_id FROM color_templates WHERE color_template_id=', $stillActive);
                        $waiting = true;
                        return;
                    }
                    usleep(20000);
                } while (microtime(true) < $deadline);
                self::fail('Production writer did not reach parent lock: ' . json_encode($observer->query('SELECT STATE,INFO FROM information_schema.PROCESSLIST WHERE ID=' . $writerId)->fetchAll(\PDO::FETCH_ASSOC)));
            };
            $owner->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [ColorTemplateRaceStatement::class, [$probe]]);
            try {
                $this->store($owner)->delete(42, [1]);
                self::assertTrue($waiting);
                self::assertFalse($owner->inTransaction());
                $result = json_decode(stream_get_contents($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
                self::assertSame(['saved' => false, 'transaction' => false], $result);
                self::assertSame('', stream_get_contents($pipes[2]));
                self::assertSame(0, (int) $owner->query("SELECT COUNT(*) FROM $table WHERE color_template=1")->fetchColumn());
                self::assertSame(1, (int) $owner->query("SELECT COUNT(*) FROM $table WHERE graph_templates_item_id=99 AND color_template=0")->fetchColumn());
            } finally {
                if (is_resource($process)) {
                    if ($owner->inTransaction()) {
                        $owner->rollBack();
                    }
                    foreach ($pipes as $pipe) {
                        fclose($pipe);
                    }
                    self::assertSame(0, proc_close($process));
                }
            }
        });
    }

    public static function waitingWriters(): array
    {
        return [
            ['aggregate_graph_templates_item', 'committed'], ['aggregate_graph_templates_item', 'repeatable'],
            ['aggregate_graphs_graph_item', 'committed'], ['aggregate_graphs_graph_item', 'repeatable'],
        ];
    }

    public static function references(): array
    {
        return [['aggregate_graph_templates_item', false], ['aggregate_graph_templates_item', true], ['aggregate_graphs_graph_item', false], ['aggregate_graphs_graph_item', true]];
    }

    public function testActualReferenceWriterPreservesAtomicReplacementAndCallerOwnership(): void
    {
        $this->scenario(function (\PDO $owner): void {
            $schema = $owner->query('SELECT DATABASE()')->fetchColumn();
            $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $schema, getenv('KADUPUL_TEST_MYSQL_DSN'));
            $process = new \Symfony\Component\Process\Process([PHP_BINARY, __DIR__ . '/color_template_reference_probe.php'], dirname(__DIR__, 2), ['KADUPUL_TEST_MYSQL_DSN' => $dsn]);
            $process->mustRun();
            self::assertSame('', $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            self::assertCount(12, $result);
            foreach ($result as $contract => $passed) {
                self::assertTrue($passed, $contract);
            }
        });
    }

    public function testItemCreationKeepsItsSelectedColorUntilCommit(): void
    {
        $this->scenario(function (\PDO $owner, \PDO $writer): void {
            $attempted = false;
            $probe = static function (string $sql) use ($writer, &$attempted): void {
                if (str_starts_with($sql, 'SELECT id FROM colors WHERE id=')) {
                    $attempted = true;
                    self::blocked($writer, 'DELETE FROM colors WHERE id=1');
                }
            };
            $owner->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [ColorTemplateRaceStatement::class, [$probe]]);
            $id = $this->store($owner)->saveItem(42, 1, null, 1, null);
            self::assertTrue($attempted);
            self::assertGreaterThan(0, $id);
            self::assertSame(1, (int) $owner->query('SELECT COUNT(*) FROM color_template_items i JOIN colors c ON c.id=i.color_id')->fetchColumn());
        });
    }

    public function testConcurrentAuthorizationKeepsSharedLocksAndBlocksRevocation(): void
    {
        $this->scenario(function (\PDO $first, \PDO $second, \PDO $revoker): void {
            $first->exec("INSERT INTO settings (name,value) VALUES ('auth_method','1'),('guest_user','0')");
            $first->exec("INSERT INTO user_auth (id,username,enabled,locked,must_change_password) VALUES (42,'first','on','',''),(43,'second','on','','')");
            $first->exec('INSERT INTO user_auth_realm (user_id,realm_id) VALUES (42,8),(42,5),(43,8),(43,5)');
            foreach ([[$first, 42], [$second, 43]] as [$db, $actor]) {
                $db->beginTransaction();
                $console = $this->createMock(ConsoleAccess::class);
                $console->method('consoleActor')->willReturnCallback(static function () use ($db, $actor): Actor {
                    // Same shared policy read that LegacyAuthenticatedSession
                    // performs before the feature adapter rechecks the actor.
                    $db->query("SELECT value FROM settings WHERE name='auth_method' LOCK IN SHARE MODE")->fetchColumn();
                    return new Actor($actor, 'operator');
                });
                (new LegacyColorTemplateAccess($console, $this->connection($db)))->assertCurrent($actor);
            }
            self::assertTrue($first->inTransaction());
            self::assertTrue($second->inTransaction());
            self::blocked($revoker, "UPDATE settings SET value='0' WHERE name='auth_method'");
            self::blocked($revoker, "UPDATE user_auth SET enabled='' WHERE id=42");
            self::blocked($revoker, 'DELETE FROM user_auth_realm WHERE user_id=43 AND realm_id=5');
            $first->rollBack();
            $second->rollBack();
        });
    }

    private static function blocked(\PDO $writer, string $sql): void
    {
        try {
            $writer->exec($sql);
            self::fail('A concurrent dependency or policy mutation bypassed its lock.');
        } catch (\PDOException $error) {
            self::assertSame(1205, $error->errorInfo[1]);
        }
    }

    private function scenario(callable $check): void
    {
        $connections = [$this->realMariaDb(), $this->realMariaDb(), $this->realMariaDb()];
        $clients = array_map(static fn($connection): \PDO => $connection->getNativeConnection(), $connections);
        $schema = 'color_race_' . bin2hex(random_bytes(6));
        $clients[0]->exec('CREATE DATABASE `' . $schema . '`');
        try {
            foreach ($clients as $client) {
                $client->exec('USE `' . $schema . '`');
                $client->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $client->exec('SET SESSION innodb_lock_wait_timeout=1');
            }
            $source = file_get_contents(__DIR__ . '/../../cacti.sql');
            foreach (['color_templates', 'color_template_items', 'colors', 'aggregate_graph_templates_item', 'aggregate_graphs_graph_item', 'settings', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_members', 'user_auth_group_realm'] as $table) {
                self::assertSame(1, preg_match('/CREATE TABLE `?' . $table . '`? \(.*?;\s/s', $source, $match));
                $clients[0]->exec($match[0]);
            }
            $clients[0]->exec("INSERT INTO color_templates (color_template_id,name) VALUES (1,'Fixture')");
            $clients[0]->exec("INSERT INTO colors (id,name,hex) VALUES (1,'Blue','0000FF')");
            $check(...$clients);
        } finally {
            foreach ($clients as $client) {
                if ($client->inTransaction()) {
                    $client->rollBack();
                }
            }
            $clients[0]->exec('DROP DATABASE `' . $schema . '`');
            foreach ($connections as $connection) {
                $connection->close();
            }
        }
    }

    private function store(\PDO $db): LegacyColorTemplateStore
    {
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);
        return new LegacyColorTemplateStore($this->connection($db), $this->createMock(ColorTemplateAccess::class), $this->createMock(AuditTrail::class), $configuration);
    }

    private function connection(\PDO $db): DatabaseConnection
    {
        $connection = $this->createMock(DatabaseConnection::class);
        $connection->method('get')->willReturn($db);
        return $connection;
    }
}

final class ColorTemplateRaceStatement extends \PDOStatement
{
    protected function __construct(private readonly \Closure $probe) {}

    public function execute(?array $params = null): bool
    {
        $result = parent::execute($params);
        if (!str_starts_with($this->queryString, 'SELECT color_template_id,name FROM color_templates WHERE color_template_id IN')) {
            ($this->probe)($this->queryString);
        }
        return $result;
    }
    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = parent::fetchAll($mode, ...$args);
        if (str_starts_with($this->queryString, 'SELECT color_template_id,name FROM color_templates WHERE color_template_id IN')) {
            $this->closeCursor();
            ($this->probe)($this->queryString);
        }
        return $rows;
    }

}
