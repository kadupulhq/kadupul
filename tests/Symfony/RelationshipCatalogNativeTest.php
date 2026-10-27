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
final class RelationshipCatalogNativeTest extends TestCase
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
        foreach (['host_template', 'host_template_snmp_query', 'snmp_query', 'snmp_query_graph', 'data_input', 'data_template', 'data_template_rrd', 'aggregate_graph_templates', 'aggregate_graph_templates_item', 'graph_templates_item', 'cdef', 'color_templates', 'automation_snmp', 'automation_snmp_items', 'automation_graph_rules', 'automation_tree_rules', 'automation_match_rule_items', 'automation_graph_rule_items', 'automation_tree_rule_items', 'snmpagent_managers', 'snmpagent_managers_notifications', 'snmpagent_notifications_log'] as $table) {
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

    public function testEveryViewUsesTheShippedSchemaAndBoundedScopedQueries(): void
    {
        $this->seed();
        foreach (\Kadupul\Navigation\Contract\Relationships\RelationshipScreen::cases() as $screen) {
            if ($screen->identityView()) {
                continue;
            }
            $catalog = $this->catalog($screen);
            foreach (array_keys($screen->columns()) as $column) {
                foreach (['ASC', 'DESC'] as $direction) {
                    $filters = ListFilters::fromColumns($screen->columns(), ['sort_column' => $column, 'sort_direction' => $direction]);
                    $this->statements = [];
                    $page = $catalog->read($screen, $filters, 4);
                    self::assertCount(2, $this->statements, $screen->value);
                    self::assertSame('0', $page->parentName);
                    self::assertCount(1, $page->records, $screen->value);
                    self::assertSame([], array_diff(array_keys($page->records[0]), [...array_keys($screen->columns()), 'id']));
                    self::assertStringNotContainsString('secret-canary', json_encode($page->records));
                }
            }
            self::assertSame([], $catalog->read($screen, ListFilters::fromColumns($screen->columns(), ['filter' => "' OR 1=1 --"]), 4)->records);
            self::assertSame([], $catalog->read($screen, ListFilters::fromColumns($screen->columns(), []), 5)->records);
            try {
                $catalog->read($screen, ListFilters::fromColumns($screen->columns(), []), 999);
                self::fail('Missing parent must not become an empty list.');
            } catch (\Kadupul\Navigation\Contract\Relationships\RelationshipParentNotFound) {
                self::assertTrue(true);
            }
        }
    }

    public function testSharedRuleTypesAndReceiverCompositeKeysArePreserved(): void
    {
        $this->seed();
        $screens = \Kadupul\Navigation\Contract\Relationships\RelationshipScreen::class;
        foreach ([[$screens::GraphDeviceCriteria, 'field1'], [$screens::TreeDeviceCriteria, 'field3']] as [$screen, $name]) {
            self::assertSame([$name], array_column($this->catalog($screen)->read($screen, ListFilters::fromColumns($screen->columns(), []), 4)->records, 'name'));
        }
        $this->database->insert('snmpagent_managers_notifications', ['manager_id' => 4, 'notification' => 'notify', 'mib' => 'MIB-B']);
        $screen = $screens::ReceiverSubscriptions;
        $page = $this->catalog($screen)->read($screen, ListFilters::fromColumns($screen->columns(), []), 4);
        self::assertSame(['MIB-A', 'MIB-B'], array_column($page->records, 'mib'));
        $this->database->executeStatement("UPDATE graph_templates_item SET graph_template_id = 11, text_format = 'unrelated-secret' WHERE id = 7");
        $screen = $screens::AggregateItems;
        $record = $this->catalog($screen)->read($screen, ListFilters::fromColumns($screen->columns(), []), 4)->records[0];
        self::assertSame(0, (int) $record['resolved']);
        self::assertNull($record['name']);
    }

    private function catalog(\Kadupul\Navigation\Contract\Relationships\RelationshipScreen $screen): \Kadupul\Navigation\Contract\RelationshipCatalog
    {
        $catalogs = [new \Kadupul\Graphing\Infrastructure\Persistence\DbalTemplateRelationships($this->database),
            new \Kadupul\Automation\Infrastructure\Persistence\DbalAutomationRelationships($this->database),
            new \Kadupul\Platform\Infrastructure\Persistence\DbalReceiverRelationships($this->database)];
        $owners = array_values(array_filter($catalogs, static fn($catalog): bool => $catalog->supports($screen)));
        self::assertCount(1, $owners);
        return $owners[0];
    }

    private function seed(): void
    {
        foreach (['host_template', 'data_template', 'automation_snmp', 'automation_graph_rules', 'automation_tree_rules'] as $table) {
            foreach ([4, 5] as $id) {
                $this->database->insert($table, ['id' => $id, 'name' => '0']);
            }
        }
        foreach ([4, 5] as $id) {
            $this->database->insert('aggregate_graph_templates', ['id' => $id, 'name' => '0', 'graph_template_id' => 10, 'gprint_prefix' => '', 'total_prefix' => '', 'graph_type' => 1, 'total' => 0, 'total_type' => 1, 'order_type' => 0, 'user_id' => 1]);
            $this->database->insert('snmpagent_managers', ['id' => $id, 'description' => '0', 'hostname' => 'localhost', 'max_log_size' => 10, 'snmp_auth_protocol' => 'MD5', 'snmp_priv_protocol' => 'DES', 'snmp_message_type' => 1, 'snmp_community' => 'secret-canary', 'snmp_username' => '', 'snmp_password' => '', 'snmp_priv_passphrase' => '', 'snmp_engine_id' => '']);
        }
        $this->database->insert('snmp_query', ['id' => 7, 'name' => 'query']);
        $this->database->insert('host_template_snmp_query', ['host_template_id' => 4, 'snmp_query_id' => 7]);
        $this->database->insert('data_template_rrd', ['id' => 7, 'data_template_id' => 4, 'data_source_name' => 'source']);
        $this->database->insert('graph_templates_item', ['id' => 7, 'graph_template_id' => 10, 'local_graph_id' => 0, 'text_format' => 'item']);
        $this->database->insert('aggregate_graph_templates_item', ['aggregate_template_id' => 4, 'graph_templates_item_id' => 7, 'color_template' => 0, 'item_skip' => '', 'item_total' => '']);
        $this->database->insert('automation_snmp_items', ['id' => 7, 'snmp_id' => 4, 'snmp_community' => 'secret-canary', 'snmp_username' => 'secret-canary', 'snmp_password' => '', 'snmp_priv_passphrase' => '', 'snmp_context' => '', 'snmp_engine_id' => '']);
        foreach ([1, 3] as $type) {
            $this->database->insert('automation_match_rule_items', ['id' => $type, 'rule_id' => 4, 'rule_type' => $type, 'field' => 'field' . $type, 'pattern' => 'pattern']);
        }
        $this->database->insert('automation_graph_rule_items', ['id' => 7, 'rule_id' => 4, 'field' => 'query', 'pattern' => 'pattern']);
        $this->database->insert('automation_tree_rule_items', ['id' => 7, 'rule_id' => 4, 'field' => 'tree', 'search_pattern' => 'search', 'replace_pattern' => 'replacement']);
        $this->database->insert('snmpagent_managers_notifications', ['manager_id' => 4, 'notification' => 'notify', 'mib' => 'MIB-A']);
        $this->database->insert('snmpagent_notifications_log', ['id' => 7, 'manager_id' => 4, 'notification' => 'notify', 'mib' => 'MIB-A', 'time' => 1700000000, 'severity' => 1, 'varbinds' => 'values']);
    }
}
