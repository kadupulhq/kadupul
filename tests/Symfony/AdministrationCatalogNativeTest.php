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
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Navigation\Application\Query\AdministrationListDenied;
use Kadupul\Navigation\Contract\Administration\ListFilters;
use Kadupul\Navigation\Contract\Administration\ListScreen;
use Kadupul\Navigation\Contract\AdministrationCatalog;
use Kadupul\Navigation\Infrastructure\Persistence\DbalAdministrationListAccess;
use Kadupul\Platform\Infrastructure\Persistence\DbalPlatformAdministrationCatalog;
use PHPUnit\Framework\TestCase;

/** Each case owns a schema and executes the shipped MySQL table definitions. */
final class AdministrationCatalogNativeTest extends TestCase
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
        $this->schema = 'kadupul_list_test_' . bin2hex(random_bytes(8));
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
        foreach (['host_template', 'host', 'graph_templates', 'graph_templates_graph', 'graph_local', 'data_template', 'data_template_data', 'data_source_profiles', 'data_input', 'aggregate_graph_templates', 'aggregate_graphs', 'snmp_query', 'snmp_query_graph', 'automation_snmp', 'automation_snmp_items', 'automation_networks', 'automation_processes', 'automation_ips', 'automation_graph_rules', 'automation_tree_rules', 'graph_tree', 'graph_tree_items', 'poller', 'snmpagent_managers', 'snmpagent_managers_notifications', 'snmpagent_notifications_log', 'settings', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_members', 'user_auth_group_realm'] as $table) {
            self::assertSame(1, preg_match('/CREATE TABLE `?' . preg_quote($table, '/') . '`?\s*\(.*?\) ENGINE=[^;]+;/is', $source, $match), $table);
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

    public function testAllTenQueriesReadActualSchemaAndEverySupportedSort(): void
    {
        foreach (ListScreen::cases() as $screen) {
            self::assertSame([], $this->catalog($screen)->read($screen, ListFilters::fromQuery($screen, [])));
        }
        $this->seed();
        foreach (ListScreen::cases() as $screen) {
            foreach (array_keys($screen->columns()) as $column) {
                foreach (['ASC', 'DESC'] as $direction) {
                    $this->statements = [];
                    $records = $this->catalog($screen)->read($screen, ListFilters::fromQuery($screen, ['sort_column' => $column, 'sort_direction' => $direction]));
                    self::assertCount(1, $this->statements, 'One bounded query per list, independent of row count.');
                    self::assertNotEmpty($records, $screen->value . ':' . $column);
                    self::assertSame([], array_diff(array_keys($records[0]), array_keys($screen->columns())), $screen->value);
                    self::assertSame([], array_diff(array_keys($screen->columns()), array_keys($records[0])), $screen->value);
                }
            }
            self::assertSame([], $this->catalog($screen)->read($screen, ListFilters::fromQuery($screen, ['filter' => "' OR 1=1 --"])));
        }
        $options = $this->read(ListScreen::SnmpOptions)[0];
        self::assertSame([2, 3, 1, 1, 1], array_map('intval', [$options['networks'], $options['totals'], $options['v1entries'], $options['v2entries'], $options['v3entries']]));
        $data = $this->read(ListScreen::DataTemplates)[0];
        self::assertSame(['Template input', 'Template profile', 'on', 2], [$data['data_input_method'], $data['profile_name'], $data['active'], (int) $data['data_sources']]);
        $queries = $this->read(ListScreen::DataQueries)[0];
        self::assertSame([3, 2], [(int) $queries['graphs'], (int) $queries['templates']]);
        $receiver = $this->read(ListScreen::NotificationReceivers)[0];
        self::assertSame([2, 3], [(int) $receiver['count_notify'], (int) $receiver['count_log']]);
        self::assertSame(2, (int) $this->database->fetchOne('SELECT COUNT(*) FROM automation_processes'));
        $network = $this->read(ListScreen::Networks)[0];
        self::assertSame('Running', $network['network_status']);
        self::assertSame([1, 1, 1, 5, 3], array_map('intval', [$network['pending'], $network['running'], $network['done'], $network['up_hosts'], $network['snmp_hosts']]));
    }

    public function testPaginationIsBoundedAndStableAcrossDuplicateNames(): void
    {
        for ($id = 1; $id <= 25; ++$id) {
            $this->insert('automation_snmp', ['id' => $id, 'name' => 'same']);
        }
        $screen = ListScreen::SnmpOptions;
        $this->statements = [];
        $first = $this->catalog($screen)->read($screen, ListFilters::fromQuery($screen, ['rows' => '10']));
        self::assertCount(1, $this->statements);
        $second = $this->catalog($screen)->read($screen, ListFilters::fromQuery($screen, ['rows' => '10', 'page' => '2']));
        self::assertSame(range(1, 11), array_map('intval', array_column($first, 'id')));
        self::assertSame(range(11, 21), array_map('intval', array_column($second, 'id')));
        self::assertSame([], $this->catalog($screen)->read($screen, ListFilters::fromQuery($screen, ['rows' => '100', 'page' => '99999'])));
    }

    public function testReadAdmissionChecksConsoleFeatureRealmAndCurrentAccount(): void
    {
        $this->insert('user_auth', ['id' => 42, 'username' => 'operator', 'enabled' => 'on', 'locked' => '', 'must_change_password' => '']);
        $this->insert('settings', ['name' => 'auth_method', 'value' => '1']);
        $console = $this->createStub(ConsoleAccess::class);
        $console->method('consoleActor')->willReturn(new Actor(42, 'operator'));
        $access = new DbalAdministrationListAccess($console, $this->database);
        foreach (ListScreen::cases() as $screen) {
            $this->database->executeStatement('DELETE FROM user_auth_realm');
            $this->assertDenied($access, $screen);
            $this->insert('user_auth_realm', ['user_id' => 42, 'realm_id' => $screen->realm()]);
            $this->assertDenied($access, $screen);
            $this->insert('user_auth_realm', ['user_id' => 42, 'realm_id' => 8]);
            $access->authorize($screen);
        }
        foreach (['enabled' => '', 'locked' => 'on', 'must_change_password' => 'on'] as $field => $value) {
            $previous = $this->database->fetchOne('SELECT ' . $field . ' FROM user_auth WHERE id = 42');
            $this->database->update('user_auth', [$field => $value], ['id' => 42]);
            $this->assertDenied($access, ListScreen::NotificationReceivers);
            $this->database->update('user_auth', [$field => $previous], ['id' => 42]);
        }
        $this->database->executeStatement('DELETE FROM user_auth_realm WHERE realm_id <> 8');
        $this->insert('user_auth_group', ['id' => 3, 'enabled' => '']);
        $this->insert('user_auth_group_members', ['group_id' => 3, 'user_id' => 42]);
        $this->insert('user_auth_group_realm', ['group_id' => 3, 'realm_id' => 15]);
        $this->assertDenied($access, ListScreen::NotificationReceivers);
        $this->database->update('user_auth_group', ['enabled' => 'on'], ['id' => 3]);
        $access->authorize(ListScreen::NotificationReceivers);
        $this->insert('settings', ['name' => 'guest_user', 'value' => 'operator']);
        $this->assertDenied($access, ListScreen::NotificationReceivers);
    }

    private function assertDenied(DbalAdministrationListAccess $access, ListScreen $screen): void
    {
        try {
            $access->authorize($screen);
            self::fail('An ungranted list was admitted: ' . $screen->value);
        } catch (AdministrationListDenied $error) {
            self::assertFalse($error->unauthenticated);
        }
    }

    private function catalog(ListScreen $screen): AdministrationCatalog
    {
        foreach ([new DbalGraphingAdministrationCatalog($this->database), new DbalDataInputAdministrationCatalog($this->database), new DbalAutomationAdministrationCatalog($this->database), new DbalPlatformAdministrationCatalog($this->database)] as $catalog) {
            if ($catalog->supports($screen)) {
                return $catalog;
            }
        }
        throw new \LogicException('Missing catalog.');
    }

    private function read(ListScreen $screen): array
    {
        return $this->catalog($screen)->read($screen, ListFilters::fromQuery($screen, []));
    }

    /** Fill required columns from actual metadata, never from an invented schema. */
    private function insert(string $table, array $values): void
    {
        foreach ($this->database->fetchAllAssociative('SHOW COLUMNS FROM `' . $table . '`') as $column) {
            if (!array_key_exists($column['Field'], $values) && $column['Null'] === 'NO' && $column['Default'] === null && !str_contains($column['Extra'], 'auto_increment')) {
                $values[$column['Field']] = preg_match('/int|float|double|decimal/', $column['Type']) ? 0 : '';
            }
        }
        $this->database->insert($table, $values);
    }

    private function seed(): void
    {
        $this->insert('host_template', ['id' => 7, 'name' => 'Device template', 'class' => 'router']);
        $this->insert('host', ['id' => 7, 'host_template_id' => 7]);
        $this->insert('graph_templates', ['id' => 7, 'name' => 'Graph template']);
        $this->insert('graph_templates_graph', ['id' => 7, 'graph_template_id' => 7, 'local_graph_id' => 0, 'height' => 100, 'width' => 200]);
        $this->insert('data_input', ['id' => 7, 'name' => 'Template input']);
        $this->insert('data_source_profiles', ['id' => 7, 'name' => 'Template profile']);
        $this->insert('data_template', ['id' => 7, 'name' => 'Data template']);
        $this->insert('data_template_data', ['id' => 7, 'data_template_id' => 7, 'local_data_id' => 0, 'data_input_id' => 7, 'data_source_profile_id' => 7, 'active' => 'on']);
        foreach ([8, 9] as $id) {
            $this->insert('data_template_data', ['id' => $id, 'data_template_id' => 7, 'local_data_id' => $id, 'active' => '', 'data_input_id' => 0]);
        }
        $this->insert('aggregate_graph_templates', ['id' => 7, 'name' => 'Aggregate template', 'graph_template_id' => 7]);
        $this->insert('aggregate_graphs', ['id' => 7, 'aggregate_template_id' => 7]);
        $this->insert('snmp_query', ['id' => 7, 'name' => 'Data query', 'data_input_id' => 7]);
        foreach ([7, 8, 9] as $id) {
            $this->insert('graph_local', ['id' => $id, 'snmp_query_id' => 7, 'graph_template_id' => 7]);
            $this->insert('snmp_query_graph', ['id' => $id, 'snmp_query_id' => 7, 'graph_template_id' => $id === 9 ? 8 : 7]);
            $this->insert('automation_snmp_items', ['id' => $id, 'snmp_id' => 7, 'snmp_version' => $id - 6]);
        }
        $this->insert('automation_snmp', ['id' => 7, 'name' => 'SNMP options']);
        foreach ([7, 8] as $id) {
            $this->insert('automation_networks', ['id' => $id, 'name' => 'Network ' . $id, 'snmp_id' => 7, 'enabled' => 'on', 'up_hosts' => 99]);
        }
        foreach ([0, 1, 2] as $status) {
            $this->insert('automation_ips', ['ip_address' => '192.0.2.' . ($status + 1), 'network_id' => 7, 'status' => $status]);
        }
        $this->insert('automation_processes', ['pid' => 1, 'network_id' => 7, 'status' => 'running', 'up_hosts' => 4, 'snmp_hosts' => 2]);
        $this->insert('automation_processes', ['pid' => 2, 'network_id' => 7, 'status' => 'done', 'up_hosts' => 1, 'snmp_hosts' => 1]);
        $this->insert('automation_graph_rules', ['id' => 7, 'name' => 'Graph rule', 'snmp_query_id' => 7, 'graph_type_id' => 7]);
        $this->insert('automation_tree_rules', ['id' => 7, 'name' => 'Tree rule', 'tree_id' => 7, 'tree_item_id' => 7]);
        $this->insert('graph_tree', ['id' => 7, 'name' => 'Tree']);
        $this->insert('graph_tree_items', ['id' => 7, 'graph_tree_id' => 7, 'title' => 'Subtree']);
        $this->insert('snmpagent_managers', ['id' => 7, 'description' => 'Receiver', 'hostname' => 'receiver.invalid']);
        foreach ([7, 8] as $id) {
            $this->insert('snmpagent_managers_notifications', ['manager_id' => 7, 'notification' => 'notification' . $id, 'mib' => 'test']);
        }
        foreach ([7, 8, 9] as $id) {
            $this->insert('snmpagent_notifications_log', ['id' => $id, 'manager_id' => 7]);
        }
    }
}
