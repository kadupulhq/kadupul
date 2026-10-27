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
final class IdentitySettingsNativeTest extends TestCase
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
        foreach (['settings_user', 'settings_user_group', 'settings', 'user_auth_realm', 'user_auth_group_realm', 'user_auth', 'user_auth_group', 'user_auth_group_members', 'user_auth_perms', 'user_auth_group_perms', 'host', 'graph_tree', 'graph_templates', 'graph_local', 'graph_templates_graph'] as $table) {
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

    public function testTenReadersUseTheActualSchemaAllSortsAndExplicitProjections(): void
    {
        $this->seed();
        $catalog = new \Kadupul\IdentityAccess\Infrastructure\Persistence\DbalIdentityRelationships($this->database);
        $count = 0;
        foreach ($this->screens() as $screen) {
            ++$count;
            foreach (array_keys($screen->columns()) as $column) {
                foreach (['ASC', 'DESC'] as $direction) {
                    $filters = ListFilters::fromColumns($screen->columns(), ['rows' => '100', 'sort_column' => $column, 'sort_direction' => $direction]);
                    $this->statements = [];
                    $page = $catalog->read($screen, $filters, 4);
                    self::assertCount(2, $this->statements, $screen->value);
                    self::assertSame('0', $page->parentName);
                    self::assertCount($screen->settingLabels() === [] ? 2 : count($screen->settingLabels()), $page->records, $screen->value);
                    self::assertStringNotContainsString('credential-canary', json_encode($page->records));
                    self::assertStringNotContainsString('other-owner-canary', json_encode($page->records));
                    foreach ($page->records as $record) {
                        self::assertSame([], array_diff(array_keys($record), [...array_keys($screen->columns()), 'id']));
                    }
                }
            }
            self::assertSame([], $catalog->read($screen, ListFilters::fromColumns($screen->columns(), ['filter' => "' OR 1=1 --"]), 4)->records);
            try {
                $catalog->read($screen, ListFilters::fromColumns($screen->columns(), []), 999);
                self::fail('Missing owner cannot become an empty settings list.');
            } catch (\Kadupul\Navigation\Contract\Relationships\RelationshipParentNotFound) {
                self::assertTrue(true);
            }
        }
        self::assertSame(10, $count);
    }

    public function testCredentialsCannotBeSearchedAndMissingOverridesDoNotBecomeDefaults(): void
    {
        $this->seed();
        $catalog = new \Kadupul\IdentityAccess\Infrastructure\Persistence\DbalIdentityRelationships($this->database);
        foreach ($this->screens() as $screen) {
            self::assertSame([], $catalog->read($screen, ListFilters::fromColumns($screen->columns(), ['filter' => 'credential-canary']), 4)->records);
            if (!str_ends_with($screen->value, '-login-options')) {
                self::assertSame([], $catalog->read($screen, ListFilters::fromColumns($screen->columns(), []), 5)->records);
            }
        }
        $this->database->insert('user_auth_group', ['id' => 4294967295, 'name' => 'large']);
        $screen = \Kadupul\Navigation\Contract\Relationships\RelationshipScreen::GroupDisplay;
        self::assertSame([], $catalog->read($screen, ListFilters::fromColumns($screen->columns(), []), 4294967295)->records);
    }

    public function testExplicitEmptyZeroAndNullValuesAndNumericUnknownRealmsAreRetained(): void
    {
        $this->seed();
        $catalog = new \Kadupul\IdentityAccess\Infrastructure\Persistence\DbalIdentityRelationships($this->database);
        $screens = \Kadupul\Navigation\Contract\Relationships\RelationshipScreen::class;
        foreach ([[$screens::UserDisplay, 'settings_user', 'user_id'], [$screens::GroupDisplay, 'settings_user_group', 'group_id']] as [$screen, $table, $owner]) {
            $this->database->update($table, ['value' => '0'], [$owner => 4, 'name' => 'page_refresh']);
            $this->database->update($table, ['value' => ''], [$owner => 4, 'name' => 'selected_theme']);
            $records = array_column($catalog->read($screen, ListFilters::fromColumns($screen->columns(), []), 4)->records, null, 'name');
            self::assertSame('0', $records['page_refresh']['value']);
            self::assertSame('', $records['selected_theme']['value']);
        }
        $records = array_column($catalog->read($screens::GroupLogin, ListFilters::fromColumns($screens::GroupLogin->columns(), []), 4)->records, null, 'name');
        self::assertNull($records['graph_settings']['value']);
        foreach ([$screens::UserRealms, $screens::GroupRealms] as $screen) {
            $records = $catalog->read($screen, ListFilters::fromColumns($screen->columns(), []), 4)->records;
            self::assertSame([0, 9999], array_map('intval', array_column($records, 'id')));
        }
    }

    public function testStablePaginationAndValueSearchStayWithinOwnerAndAllowlist(): void
    {
        $this->seed();
        $catalog = new \Kadupul\IdentityAccess\Infrastructure\Persistence\DbalIdentityRelationships($this->database);
        $screen = \Kadupul\Navigation\Contract\Relationships\RelationshipScreen::UserGraphPreferences;
        $ids = [];
        foreach (['1', '2'] as $page) {
            $result = $catalog->read($screen, ListFilters::fromColumns($screen->columns(), ['rows' => '10', 'page' => $page]), 4);
            self::assertLessThanOrEqual(11, count($result->records));
            $ids = [...$ids, ...array_column(array_slice($result->records, 0, 10), 'id')];
        }
        $expected = array_keys($screen->settingLabels());
        sort($expected);
        self::assertCount(count($expected), array_unique($ids));
        sort($ids);
        self::assertSame($expected, $ids);
        $records = $catalog->read($screen, ListFilters::fromColumns($screen->columns(), ['filter' => 'owner4-show_graph_title']), 4)->records;
        self::assertSame(['show_graph_title'], array_column($records, 'id'));
        self::assertSame(1, (int) $this->database->fetchOne("SELECT COUNT(*) FROM settings WHERE name = 'selected_theme'"));
    }

    private function screens(): array
    {
        return array_values(array_filter(\Kadupul\Navigation\Contract\Relationships\RelationshipScreen::cases(), static fn($screen): bool => $screen->identitySettingsView()));
    }

    private function seed(): void
    {
        foreach ([4, 5, 7] as $id) {
            $this->database->insert('user_auth', ['id' => $id, 'username' => '0', 'password' => 'credential-canary', 'password_history' => 'credential-canary']);
            $this->database->insert('user_auth_group', ['id' => $id, 'name' => '0']);
        }
        $this->database->insert('settings', ['name' => 'selected_theme', 'value' => 'server-default']);
        foreach (['user_auth_realm' => 'user_id', 'user_auth_group_realm' => 'group_id'] as $table => $owner) {
            foreach ([0, 9999] as $realm) {
                $this->database->insert($table, [$owner => 4, 'realm_id' => $realm]);
            }
            $this->database->insert($table, [$owner => 7, 'realm_id' => 12]);
        }
        foreach ($this->screens() as $screen) {
            if (str_ends_with($screen->value, '-login-options')) {
                continue;
            }
            $user = $screen->parentScreen() === ListScreen::Users;
            $table = $user ? 'settings_user' : 'settings_user_group';
            $owner = $user ? 'user_id' : 'group_id';
            foreach (array_keys($screen->settingLabels()) as $key) {
                $this->database->insert($table, [$owner => 4, 'name' => $key, 'value' => 'owner4-' . $key]);
                $this->database->insert($table, [$owner => 7, 'name' => $key, 'value' => 'other-owner-canary']);
            }
        }
        foreach (['settings_user' => 'user_id', 'settings_user_group' => 'group_id'] as $table => $owner) {
            foreach (['password', 'plugin_api_key', 'remember_me', 'unknown_setting'] as $key) {
                $this->database->insert($table, [$owner => 4, 'name' => $key, 'value' => 'credential-canary']);
            }
        }
    }
}
