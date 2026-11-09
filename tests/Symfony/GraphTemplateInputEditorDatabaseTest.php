<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\DriverManager;
use Kadupul\Graphing\Application\Port\GraphTemplateInputEditor;
use Kadupul\Graphing\Application\Query\GraphTemplateInputAccessDenied;
use Kadupul\Graphing\Infrastructure\Persistence\DbalGraphTemplateInputAccess;
use Kadupul\Graphing\Infrastructure\Persistence\DbalGraphTemplateInputCatalog;
use Kadupul\Graphing\Infrastructure\Persistence\DbalGraphTemplateInputEditor;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The graph item input editor on MySQL or MariaDB with the shipped table
 * definitions, in a schema of its own, with the real realm checks. A save
 * must be one InnoDB transaction under the editor's row locks.
 */
final class GraphTemplateInputEditorDatabaseTest extends TestCase
{
    private const array TABLES = ['graph_templates', 'graph_template_input', 'graph_template_input_defs', 'graph_templates_item',
        'settings', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_members', 'user_auth_group_realm'];

    private ?PDO $administrator = null;
    private string $schema = '';
    /** @var array<string, mixed> */
    private array $parameters = [];
    private Connection $database;
    private DbalGraphTemplateInputEditor $editor;
    private DbalGraphTemplateInputCatalog $catalog;
    /** Runs once, right after the editor has locked the graph template. */
    private ?\Closure $afterTemplateLock = null;

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!$dsn) {
            self::markTestSkipped('KADUPUL_TEST_MYSQL_DSN is required for database contracts');
        }
        $user = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root');
        $password = getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '');
        $this->administrator = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->schema = 'graph_input_' . bin2hex(random_bytes(8));
        $this->administrator->exec('CREATE DATABASE `' . $this->schema . '`');
        $this->administrator->exec('USE `' . $this->schema . '`');
        $schema = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        self::assertIsString($schema);
        foreach (self::TABLES as $table) {
            self::assertSame(1, preg_match('/CREATE TABLE `?' . $table . '`? \(.*?\n\) ENGINE=[^;]*;/s', $schema, $match), $table);
            $this->administrator->exec($match[0]);
        }
        preg_match_all('/(\w+)=([^;]*)/', substr($dsn, strlen('mysql:')), $pairs, PREG_SET_ORDER);
        $options = array_column($pairs, 2, 1);
        $this->parameters = ['driver' => 'pdo_mysql', 'host' => $options['host'] ?? '127.0.0.1', 'port' => (int) ($options['port'] ?? 3306), 'dbname' => $this->schema,
            'user' => $user, 'password' => $password, 'charset' => 'utf8mb4'];
        $hook = function (): void {
            $callback = $this->afterTemplateLock;
            $this->afterTemplateLock = null;
            if ($callback !== null) {
                $callback();
            }
        };
        $this->database = DriverManager::getConnection($this->parameters, (new Configuration())->setMiddlewares([self::interleave($hook)]));
        $this->database->executeStatement("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        foreach ([
            "INSERT INTO settings (name, value) VALUES ('auth_method', '1'), ('guest_user', '0')",
            "INSERT INTO user_auth (id, username, password, enabled) VALUES (7, 'operator', '', 'on')",
            'INSERT INTO user_auth_realm (realm_id, user_id) VALUES (8, 7), (10, 7)',
            "INSERT INTO graph_templates (id, hash, name) VALUES (1, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'Traffic'), (2, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'Other')",
            // Template 1 owns items 11 to 13; template 2 owns item 21.
            "INSERT INTO graph_templates_item (id, hash, graph_template_id, local_graph_id, graph_type_id, consolidation_function_id, sequence, text_format) VALUES
                (11, '', 1, 0, 4, 1, 1, 'In'), (12, '', 1, 0, 9, 4, 2, 'Cur'), (13, '', 1, 0, 7, 3, 3, 'Max'), (21, '', 2, 0, 4, 1, 1, 'Foreign')",
            // Graph 100 was built from template 1.
            "INSERT INTO graph_templates_item (id, hash, local_graph_template_item_id, local_graph_id, graph_template_id, text_format) VALUES
                (1011, '', 11, 100, 1, 'Inbound'), (1012, '', 12, 100, 1, 'Old 12'), (1013, '', 13, 100, 1, 'Old 13')",
            "INSERT INTO graph_template_input (id, hash, graph_template_id, name, description, column_name) VALUES (5, '0123456789abcdef0123456789abcdef', 1, 'Legend', 'Text', 'text_format')",
            'INSERT INTO graph_template_input_defs (graph_template_input_id, graph_template_item_id) VALUES (5, 11)',
        ] as $statement) {
            $this->database->executeStatement($statement);
        }
        $console = $this->createStub(ConsoleAccess::class);
        $console->method('consoleActor')->willReturn(new Actor(7, 'operator'));
        $configuration = $this->createStub(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);
        $this->editor = new DbalGraphTemplateInputEditor($this->database, new DbalGraphTemplateInputAccess($console, $this->database), $this->createStub(AuditTrail::class), $configuration, new NullLogger());
        $this->catalog = new DbalGraphTemplateInputCatalog($this->database);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
        if ($this->administrator !== null && $this->schema !== '') {
            $this->administrator->exec('DROP DATABASE IF EXISTS `' . $this->schema . '`');
        }
    }

    public function testASaveCommitsTheInputItsItemsAndThePushedValuesTogether(): void
    {
        self::assertSame(5, $this->editor->save(7, 1, 5, 'Renamed', 'Desc', 'text_format', [11, 12], $this->revision()));
        self::assertSame(['Renamed', [11, 12]], [$this->catalog->find(1, 5)?->name, $this->catalog->find(1, 5)?->itemIds]);
        self::assertSame(['Inbound', 'Inbound', 'Old 13'], $this->texts());
        $created = $this->editor->save(7, 1, null, str_repeat('é', 255), '', 'color_id', [13], null);
        self::assertSame([13], $this->catalog->find(1, $created)?->itemIds);
        self::assertSame(1, preg_match('/\A[0-9a-f]{32}\z/', (string) $this->database->fetchOne('SELECT hash FROM graph_template_input WHERE id = ?', [$created])));
        $this->editor->remove(7, 1, $created, $this->catalog->find(1, $created)->revision ?? '');
        self::assertNull($this->catalog->find(1, $created));
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM graph_template_input_defs WHERE graph_template_input_id = ?', [$created]));
    }

    public function testAFailurePartwayThroughTheRewriteRollsEverythingBack(): void
    {
        // The input row is updated and values are pushed out before the
        // item set is deleted and reinserted; the reinsert then fails.
        $this->administrator?->exec("CREATE TRIGGER refuse_item_13 BEFORE INSERT ON graph_template_input_defs FOR EACH ROW
            BEGIN IF NEW.graph_template_item_id = 13 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused'; END IF; END");
        $before = $this->rows();
        try {
            $this->editor->save(7, 1, 5, 'Renamed', 'Desc', 'text_format', [11, 12, 13], $this->revision());
            self::fail('The refused item set was saved.');
        } catch (\Throwable $error) {
            self::assertStringContainsString('refused', $error->getMessage());
        }
        self::assertFalse($this->database->isTransactionActive());
        self::assertSame($before, $this->rows());
    }

    public function testAForeignItemIsRefusedWithNothingWritten(): void
    {
        $before = $this->rows();
        foreach ([[11, 21], [1012], [11, 99]] as $items) {
            try {
                $this->editor->save(7, 1, 5, 'Renamed', '', 'text_format', $items, $this->revision());
                self::fail('A foreign item was accepted: ' . implode(',', $items));
            } catch (\InvalidArgumentException $error) {
                self::assertSame(GraphTemplateInputEditor::RELATIONSHIP, $error->getMessage());
            }
            self::assertSame($before, $this->rows());
        }
    }

    public function testAStaleRevisionIsRefusedWithNothingWritten(): void
    {
        $revision = $this->revision();
        $this->database->executeStatement("UPDATE graph_template_input SET name = 'Changed elsewhere' WHERE id = 5");
        $before = $this->rows();
        foreach (['save', 'remove'] as $operation) {
            try {
                $operation === 'save'
                    ? $this->editor->save(7, 1, 5, 'Mine', '', 'text_format', [11, 12], $revision)
                    : $this->editor->remove(7, 1, 5, $revision);
                self::fail('A stale ' . $operation . ' was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame(GraphTemplateInputEditor::STALE, $error->getMessage());
            }
            self::assertSame($before, $this->rows());
        }
    }

    public function testAChangeCommittedWhileTheSaveRunsIsSeenAsStale(): void
    {
        // Another session renames the input after the save has opened its
        // transaction and taken its first lock. The save's locking read must
        // see that commit rather than its snapshot, so it refuses as stale
        // instead of overwriting the rename.
        $this->afterTemplateLock = function (): void {
            $other = DriverManager::getConnection($this->parameters);
            $other->executeStatement("UPDATE graph_template_input SET name = 'Renamed elsewhere' WHERE id = 5");
            $other->close();
        };
        try {
            $this->editor->save(7, 1, 5, 'Mine', '', 'text_format', [11, 12], $this->revision());
            self::fail('A save overwrote a concurrent change.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame(GraphTemplateInputEditor::STALE, $error->getMessage());
        }
        self::assertNull($this->afterTemplateLock, 'the concurrent change ran');
        self::assertSame(['Renamed elsewhere', [11]], [$this->catalog->find(1, 5)?->name, $this->catalog->find(1, 5)?->itemIds]);
    }

    public function testASaveBlockedByAnotherWriterGivesUpWithoutWriting(): void
    {
        $other = DriverManager::getConnection($this->parameters);
        $other->beginTransaction();
        $other->fetchOne('SELECT id FROM graph_template_input WHERE id = 5 FOR UPDATE');
        $before = $this->rows();
        $this->database->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $this->editor->save(7, 1, 5, 'Renamed', '', 'text_format', [11, 12], $this->revision());
            self::fail('The save did not wait for the input row lock.');
        } catch (\Throwable $error) {
            self::assertStringContainsString('Lock wait timeout', $error->getMessage());
        } finally {
            $other->rollBack();
            $other->close();
        }
        self::assertSame($before, $this->rows());
    }

    public function testRevokingTheRealmBeforeTheWriteRefusesIt(): void
    {
        $this->database->executeStatement('DELETE FROM user_auth_realm WHERE user_id = 7 AND realm_id = 8');
        $before = $this->rows();
        $this->expectException(GraphTemplateInputAccessDenied::class);
        try {
            $this->editor->save(7, 1, 5, 'Renamed', '', 'text_format', [11], $this->revision());
        } finally {
            self::assertSame($before, $this->rows());
        }
    }

    private static function interleave(\Closure $hook): Middleware
    {
        return new class ($hook) implements Middleware {
            public function __construct(private \Closure $hook) {}

            public function wrap(Driver $driver): Driver
            {
                return new class ($driver, $this->hook) extends AbstractDriverMiddleware {
                    public function __construct(Driver $driver, private \Closure $hook)
                    {
                        parent::__construct($driver);
                    }

                    public function connect(#[\SensitiveParameter] array $params): DriverConnection
                    {
                        return new class (parent::connect($params), $this->hook) extends AbstractConnectionMiddleware {
                            public function __construct(DriverConnection $connection, private \Closure $hook)
                            {
                                parent::__construct($connection);
                            }

                            public function prepare(string $sql): Statement
                            {
                                $statement = parent::prepare($sql);
                                if (!str_starts_with($sql, 'SELECT id FROM graph_templates WHERE id = ?')) {
                                    return $statement;
                                }
                                return new class ($statement, $this->hook) extends AbstractStatementMiddleware {
                                    public function __construct(Statement $statement, private \Closure $hook)
                                    {
                                        parent::__construct($statement);
                                    }

                                    public function execute(): Result
                                    {
                                        $result = parent::execute();
                                        ($this->hook)();
                                        return $result;
                                    }
                                };
                            }
                        };
                    }
                };
            }
        };
    }

    private function revision(): string
    {
        $input = $this->catalog->find(1, 5);
        self::assertNotNull($input);
        return $input->revision;
    }

    /** @return list<string> */
    private function texts(): array
    {
        return array_map(strval(...), $this->database->fetchFirstColumn('SELECT text_format FROM graph_templates_item WHERE local_graph_id = 100 ORDER BY local_graph_template_item_id'));
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function rows(): array
    {
        $rows = [];
        foreach (['graph_template_input', 'graph_template_input_defs', 'graph_templates_item'] as $table) {
            $rows[$table] = $this->database->fetchAllAssociative("SELECT * FROM $table ORDER BY 1, 2");
        }
        return $rows;
    }
}
