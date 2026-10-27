<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Logging\Middleware;
use Psr\Log\AbstractLogger;
use Doctrine\DBAL\DriverManager;
use Kadupul\Automation\Infrastructure\Persistence\DbalAutomationAdministrationCatalog;
use Kadupul\DataInput\Infrastructure\Persistence\DbalDataInputAdministrationCatalog;
use Kadupul\Graphing\Infrastructure\Persistence\DbalGraphingAdministrationCatalog;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Infrastructure\Persistence\DbalIdentityAccessAdministrationCatalog;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Navigation\Application\Query\AdministrationListDenied;
use Kadupul\Navigation\Contract\Administration\ListFilters;
use Kadupul\Navigation\Contract\Administration\ListScreen;
use Kadupul\Navigation\Contract\AdministrationCatalog;
use Kadupul\Navigation\Infrastructure\Persistence\DbalAdministrationListAccess;
use Kadupul\Platform\Infrastructure\Persistence\DbalPlatformAdministrationCatalog;
use PHPUnit\Framework\TestCase;

/** Each case owns a schema and executes the shipped MySQL table definitions. */
final class IdentityRelationshipsNativeTest extends TestCase
{
    private ?\PDO $admin = null;
    private ?Connection $database = null;
    private ?string $schema = null;
    /** @var list<string> */
    private array $statements = [];

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')) {
            self::markTestSkipped('Set KADUPUL_TEST_MYSQL_DSN for native administration catalog verification.');
        }
        $this->admin = new \PDO($dsn, getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root'), getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->schema = 'kadupul_relationship_test_' . bin2hex(random_bytes(8));
        $this->admin->exec('CREATE DATABASE `' . $this->schema . '` CHARACTER SET utf8mb4');
        $params = ['driver' => 'pdo_mysql', 'user' => getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root'), 'password' => getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '')];
        foreach (explode(';', substr($dsn, 6)) as $part) {
            if (str_contains($part, '=')) {
                [$key, $value] = explode('=', $part, 2);
                if (in_array($key, ['host', 'port', 'unix_socket'], true)) {
                    $params[$key] = $value;
                }
            }
        }
        $params['dbname'] = $this->schema;
        $params['charset'] = 'utf8mb4';
        $configuration = new Configuration();
        $logger = new class (fn(string $sql) => $this->statements[] = $sql) extends AbstractLogger {
            public function __construct(private \Closure $record) {}
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (isset($context['sql'])) {
                    ($this->record)($context['sql']);
                }
            }
        };
        $configuration->setMiddlewares([new Middleware($logger)]);
        $this->database = DriverManager::getConnection($params, $configuration);
        $this->database->executeStatement("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY'");
        $source = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        self::assertNotFalse($source);
        foreach (['settings', 'user_auth_realm', 'user_auth_group_realm', 'user_auth', 'user_auth_group', 'user_auth_group_members', 'user_auth_perms', 'user_auth_group_perms', 'host', 'graph_tree', 'graph_templates', 'graph_local', 'graph_templates_graph'] as $table) {
            self::assertSame(1, preg_match('/CREATE TABLE `?' . preg_quote($table, '/') . '`?\s*\([^;]*;/is', $source, $match), $table);
            $this->database->executeStatement($match[0]);
        }
    }

    protected function tearDown(): void
    {
        $this->database?->close();
        if ($this->admin !== null && $this->schema !== null) {
            $this->admin->exec('DROP DATABASE `' . $this->schema . '`');
        }
    }

    public function testAllTenViewsRespectOwnerAndResourceIdentityAndEverySort(): void
    {
        $this->seed();
        $catalog = new \Kadupul\IdentityAccess\Infrastructure\Persistence\DbalIdentityRelationships($this->database);
        $count = 0;
        foreach (\Kadupul\Navigation\Contract\Relationships\RelationshipScreen::cases() as $screen) {
            if (!$catalog->supports($screen)) {
                continue;
            }
            ++$count;
            foreach (array_keys($screen->columns()) as $column) {
                foreach (['ASC', 'DESC'] as $direction) {
                    $filters = ListFilters::fromColumns($screen->columns(), ['sort_column' => $column, 'sort_direction' => $direction]);
                    $this->statements = [];
                    $page = $catalog->read($screen, $filters, 4);
                    self::assertCount(2, $this->statements);
                    self::assertSame('0', $page->parentName);
                    self::assertCount(1, $page->records, $screen->value);
                    self::assertSame(7, (int) $page->records[0]['id']);
                    self::assertStringNotContainsString('secret-canary', json_encode($page->records));
                    self::assertSame([], array_diff(array_keys($page->records[0]), array_keys($screen->columns())));
                }
            }
            self::assertSame([], $catalog->read($screen, ListFilters::fromColumns($screen->columns(), []), 5)->records);
            self::assertSame([], $catalog->read($screen, ListFilters::fromColumns($screen->columns(), ['filter' => "' OR 1=1 --"]), 4)->records);
            try {
                $catalog->read($screen, ListFilters::fromColumns($screen->columns(), []), 999);
                self::fail('Missing parent must not return an empty list.');
            } catch (\Kadupul\Navigation\Contract\Relationships\RelationshipParentNotFound) {
                self::assertTrue(true);
            }
        }
        self::assertSame(10, $count);
    }

    public function testDuplicateGraphMetadataOrphansAndPoliciesRemainDiagnostic(): void
    {
        $this->seed();
        $catalog = new \Kadupul\IdentityAccess\Infrastructure\Persistence\DbalIdentityRelationships($this->database);
        $screens = \Kadupul\Navigation\Contract\Relationships\RelationshipScreen::class;
        $this->database->insert('graph_templates_graph', ['id' => 8, 'local_graph_id' => 7, 'title_cache' => 'Duplicate title']);
        $screen = $screens::UserGraphs;
        $page = $catalog->read($screen, ListFilters::fromColumns($screen->columns(), []), 4);
        self::assertCount(1, $page->records);
        self::assertSame(1, (int) $page->records[0]['policy']);
        $this->database->executeStatement('UPDATE user_auth SET policy_graphs = 2 WHERE id = 4');
        $page = $catalog->read($screen, ListFilters::fromColumns($screen->columns(), []), 4);
        self::assertSame(2, (int) $page->records[0]['policy']);
        $this->database->executeStatement('DELETE FROM graph_local WHERE id = 7');
        $record = $catalog->read($screen, ListFilters::fromColumns($screen->columns(), []), 4)->records[0];
        self::assertSame(7, (int) $record['id']);
        self::assertSame(0, (int) $record['resolved']);
        self::assertNull($record['name']);
        self::assertSame(2, (int) $this->database->fetchOne('SELECT COUNT(*) FROM user_auth_group_members'));
        self::assertSame(4, (int) $this->database->fetchOne('SELECT COUNT(*) FROM user_auth_perms'));
    }

    public function testStablePaginationRetainsAllExceptionsWithoutReadingCredentials(): void
    {
        $this->seed();
        for ($id = 8; $id <= 31; ++$id) {
            $this->database->insert('user_auth_perms', ['user_id' => 4, 'item_id' => $id, 'type' => 3]);
        }
        $catalog = new \Kadupul\IdentityAccess\Infrastructure\Persistence\DbalIdentityRelationships($this->database);
        $screen = \Kadupul\Navigation\Contract\Relationships\RelationshipScreen::UserDevices;
        $ids = [];
        foreach (['1', '2', '3'] as $page) {
            $filters = ListFilters::fromColumns($screen->columns(), ['page' => $page, 'rows' => '10', 'sort_column' => 'id']);
            $result = $catalog->read($screen, $filters, 4);
            self::assertLessThanOrEqual(11, count($result->records));
            $ids = [...$ids, ...array_map('intval', array_column(array_slice($result->records, 0, 10), 'id'))];
        }
        self::assertSame(range(7, 31), $ids);
        foreach ($this->statements as $sql) {
            if (!str_starts_with($sql, 'SELECT')) {
                continue;
            }
            self::assertDoesNotMatchRegularExpression('/password|password_history|SELECT \* FROM user_auth/i', $sql);
        }
    }

    public function testAllTenViewsRequireCurrentConsoleAndUserAdministrationGrants(): void
    {
        $this->seed();
        $actor = new \Kadupul\IdentityAccess\Contract\Actor(42, 'operator');
        $console = $this->createStub(ConsoleAccess::class);
        $console->method('consoleActor')->willReturnCallback(static function () use (&$actor) {
            return $actor;
        });
        $this->database->insert('user_auth', ['id' => 42, 'username' => 'operator', 'enabled' => 'on']);
        $this->database->insert('user_auth_realm', ['user_id' => 42, 'realm_id' => 8]);
        $access = new \Kadupul\Navigation\Infrastructure\Persistence\ParentRelationshipAccess(new DbalAdministrationListAccess($console, $this->database));
        $screens = array_filter(\Kadupul\Navigation\Contract\Relationships\RelationshipScreen::cases(), static fn($screen): bool => $screen->identityView());
        $deny = static function () use ($access, $screens): void {
            foreach ($screens as $screen) {
                try {
                    $access->authorize($screen);
                    self::fail('User administration grant is required.');
                } catch (AdministrationListDenied) {
                    self::assertTrue(true);
                }
            }
        };
        $deny();
        $this->database->insert('user_auth_realm', ['user_id' => 42, 'realm_id' => 1]);
        foreach ($screens as $screen) {
            $access->authorize($screen);
            self::assertTrue(true);
        }
        $this->database->delete('user_auth_realm', ['user_id' => 42, 'realm_id' => 1]);
        $this->database->insert('user_auth_group_members', ['user_id' => 42, 'group_id' => 7]);
        $this->database->insert('user_auth_group_realm', ['group_id' => 7, 'realm_id' => 1]);
        foreach ($screens as $screen) {
            $access->authorize($screen);
            self::assertTrue(true);
        }
        $this->database->update('user_auth_group', ['enabled' => ''], ['id' => 7]);
        $deny();
        $this->database->update('user_auth_group', ['enabled' => 'on'], ['id' => 7]);
        foreach (['enabled' => '', 'locked' => 'on', 'must_change_password' => 'on'] as $column => $value) {
            $this->database->update('user_auth', [$column => $value], ['id' => 42]);
            $deny();
            $this->database->update('user_auth', [$column => $column === 'enabled' ? 'on' : ''], ['id' => 42]);
        }
        $this->database->delete('user_auth_realm', ['user_id' => 42, 'realm_id' => 8]);
        $deny();
        $actor = null;
        $deny();
    }

    private function seed(): void
    {
        foreach ([4, 5, 7] as $id) {
            $this->database->insert('user_auth', ['id' => $id, 'username' => $id === 7 ? 'member' : '0', 'realm' => 3, 'password' => 'secret-canary', 'password_history' => 'secret-canary']);
            $this->database->insert('user_auth_group', ['id' => $id, 'name' => $id === 7 ? 'group' : '0']);
        }
        $this->database->insert('user_auth_group_members', ['user_id' => 4, 'group_id' => 7]);
        $this->database->insert('user_auth_group_members', ['user_id' => 7, 'group_id' => 4]);
        foreach (['user_auth_perms' => 'user_id', 'user_auth_group_perms' => 'group_id'] as $table => $owner) {
            foreach ([1, 2, 3, 4] as $type) {
                $this->database->insert($table, [$owner => 4, 'item_id' => 7, 'type' => $type]);
            }
        }
        $this->database->insert('host', ['id' => 7, 'description' => 'Device']);
        $this->database->insert('graph_templates', ['id' => 7, 'name' => 'Template']);
        $this->database->insert('graph_tree', ['id' => 7, 'name' => 'Tree']);
        $this->database->insert('graph_local', ['id' => 7]);
        $this->database->insert('graph_templates_graph', ['id' => 7, 'local_graph_id' => 7, 'title_cache' => 'Graph']);
    }
}
