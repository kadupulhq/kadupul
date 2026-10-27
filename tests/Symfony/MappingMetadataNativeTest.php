<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Navigation\Contract\Management\CatalogFilters;
use Kadupul\Navigation\Contract\Management\CatalogPage;
use Kadupul\Navigation\Contract\Management\CatalogParentNotFound;
use Kadupul\Navigation\Contract\Management\CatalogScreen;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class MappingMetadataNativeTest extends TestCase
{
    private const array TABLES = ['graph_templates', 'graph_template_input', 'graph_template_input_defs', 'graph_templates_item', 'snmp_query', 'snmp_query_graph', 'snmp_query_graph_rrd', 'snmp_query_graph_sv', 'snmp_query_graph_rrd_sv', 'data_template', 'data_template_rrd', 'host_template', 'host_template_graph', 'host_template_snmp_query', 'snmpagent_mibs', 'snmpagent_cache', 'snmpagent_cache_notifications', 'snmpagent_cache_textual_conventions'];
    private ?\PDO $admin = null;
    private string $schema = '';
    private Connection $database;
    private array $catalogs;
    private int $queries = 0;

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!$dsn) {
            self::markTestSkipped('KADUPUL_TEST_MYSQL_DSN is required for native management contracts.');
        }
        $user = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root');
        $password = getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '');
        $this->admin = new \PDO($dsn, $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->schema = 'management_test_' . bin2hex(random_bytes(8));
        $this->admin->exec('CREATE DATABASE `' . $this->schema . '` CHARACTER SET utf8mb4');
        $this->admin->exec('USE `' . $this->schema . '`');
        $this->admin->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        $schema = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        self::assertIsString($schema);
        foreach (self::TABLES as $table) {
            self::assertSame(1, preg_match('/CREATE TABLE `?' . $table . '`?\s*\([^;]*;/s', $schema, $match), $table);
            $this->admin->exec($match[0]);
        }
        preg_match_all('/(\w+)=([^;]*)/', substr($dsn, 6), $pairs, PREG_SET_ORDER);
        $options = array_column($pairs, 2, 1);
        $configuration = new \Doctrine\DBAL\Configuration();
        $logger = new class (function (): void {
            $this->queries++;
        }) extends \Psr\Log\AbstractLogger {
            public function __construct(private readonly \Closure $record) {}
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ($level === 'debug') {
                    ($this->record)();
                }
            }
        };
        $configuration->setMiddlewares([new \Doctrine\DBAL\Logging\Middleware($logger)]);
        $this->database = DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => $options['host'] ?? '127.0.0.1', 'port' => (int) ($options['port'] ?? 3306), 'dbname' => $this->schema, 'user' => $user, 'password' => $password, 'charset' => 'utf8mb4'], $configuration);
        $this->database->executeStatement("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        $this->catalogs = [new \Kadupul\Graphing\Infrastructure\Persistence\DbalMappingCatalog($this->database), new \Kadupul\Platform\Infrastructure\Persistence\DbalMibCatalog($this->database)];
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
        if ($this->admin !== null && $this->schema !== '') {
            $this->admin->exec('DROP DATABASE IF EXISTS `' . $this->schema . '`');
        }
    }

    public function testAllTenViewsReadShippedTablesEverySortAndBoundedQueries(): void
    {
        $this->seed();
        $count = 0;
        foreach (CatalogScreen::cases() as $screen) {
            if (!$screen->metadataView()) {
                continue;
            }
            ++$count;
            foreach (array_keys($screen->columns()) as $column) {
                foreach (['ASC', 'DESC'] as $direction) {
                    $this->queries = 0;
                    $page = $this->read($screen, ['sort_column' => $column, 'sort_direction' => $direction]);
                    self::assertCount($screen === CatalogScreen::Mibs ? 2 : 1, $page->records, $screen->value);
                    self::assertSame($screen->scoped() ? 2 : 1, $this->queries);
                    self::assertStringNotContainsString('private-canary', json_encode($page->records));
                    foreach ($page->records as $record) {
                        self::assertSame([], array_diff(array_keys($record), [...array_keys($screen->columns()), 'id']));
                    }
                }
            }
            self::assertSame([], $this->read($screen, ['filter' => "' OR 1=1 --"])->records);
            if ($screen->scoped()) {
                self::assertSame('0', $this->read($screen)->parentName);
            }
        }
        self::assertSame(10, $count);
        self::assertSame(['action' => 'input_edit', 'id' => 4, 'graph_template_id' => 10], $this->read(CatalogScreen::InputItems)->operationParameters);
        self::assertSame(['action' => 'item_edit', 'id' => 4, 'snmp_query_id' => 10], $this->read(CatalogScreen::QuerySources)->operationParameters);
    }

    public function testForeignLocalMetadataCannotResolveAnAssociation(): void
    {
        $this->seed();
        $this->database->update('graph_templates_item', ['local_graph_id' => 8, 'text_format' => 'private-canary'], ['id' => 7]);
        $item = $this->read(CatalogScreen::InputItems)->records[0];
        self::assertSame(7, (int) $item['id']);
        self::assertSame(0, (int) $item['resolved']);
        self::assertNull($item['name']);
        $this->database->update('data_template_rrd', ['data_template_id' => 99, 'data_source_name' => 'private-canary'], ['id' => 7]);
        $item = $this->read(CatalogScreen::QuerySources)->records[0];
        self::assertSame(0, (int) $item['resolved']);
        self::assertNull($item['data_source']);
        foreach (CatalogScreen::cases() as $screen) {
            if (!$screen->metadataView() || !$screen->scoped()) {
                continue;
            }
            try {
                $this->read($screen, parent: 999);
                self::fail('Missing parent must not become an empty list.');
            } catch (CatalogParentNotFound) {
                self::assertTrue(true);
            }
            self::assertSame([], $this->read($screen, parent: 5)->records);
        }
    }

    public function testMibCompositeKeysAndUint32ParentsRemainDistinct(): void
    {
        $this->seed();
        $this->database->insert('snmpagent_cache_textual_conventions', ['name' => 'convention', 'mib' => '0', 'type' => 'B', 'description' => 'second']);
        $this->database->insert('snmpagent_cache_notifications', ['name' => 'notify', 'mib' => '0', 'attribute' => 'attribute', 'sequence_id' => 2]);
        self::assertSame(['A', 'B'], array_column($this->read(CatalogScreen::MibConventions)->records, 'type'));
        self::assertSame([1, 2], array_map('intval', array_column($this->read(CatalogScreen::MibAttributes)->records, 'sequence')));
        $this->database->insert('snmpagent_mibs', ['id' => 4294967295, 'name' => '0', 'file' => 'private-canary']);
        self::assertCount(1, $this->read(CatalogScreen::MibObjects, parent: 4294967295)->records);
        self::assertSame(['action' => 'view_snmpagent_cache'], $this->read(CatalogScreen::MibObjects)->operationParameters);
    }

    public function testCompositeSourceMappingsAndSuggestedTextAreLosslessAndPaged(): void
    {
        $this->seed();
        $this->database->insert('snmp_query_graph_rrd', ['snmp_query_graph_id' => 4, 'data_template_id' => 11, 'data_template_rrd_id' => 7, 'snmp_field_name' => 'field']);
        self::assertSame([10, 11], array_map('intval', array_column($this->read(CatalogScreen::QuerySources)->records, 'data_template_id')));
        for ($id = 8; $id <= 31; ++$id) {
            $this->database->insert('snmp_query_graph_sv', ['id' => $id, 'snmp_query_graph_id' => 4, 'field_name' => 'same', 'text' => '|query_ifName|,0,IF']);
        }
        $ids = [];
        foreach (['1', '2', '3'] as $page) {
            $result = $this->read(CatalogScreen::QueryGraphSuggestions, ['rows' => '10', 'page' => $page, 'sort_column' => 'id']);
            self::assertLessThanOrEqual(11, count($result->records));
            $ids = [...$ids, ...array_map('intval', array_column(array_slice($result->records, 0, 10), 'id'))];
        }
        self::assertSame(range(7, 31), $ids);
        self::assertSame('|query_ifName|,0,IF', $this->read(CatalogScreen::QueryGraphSuggestions)->records[0]['value']);
    }

    private function read(CatalogScreen $screen, array $query = [], int $parent = 4): CatalogPage
    {
        foreach ($this->catalogs as $catalog) {
            if ($catalog->supports($screen)) {
                return $catalog->read($screen, CatalogFilters::fromQuery($screen, $query), 42, $screen->scoped() ? $parent : 0);
            }
        }
        throw new \LogicException('No catalog owner.');
    }

    private function seed(): void
    {
        foreach ([4, 5] as $id) {
            $this->database->insert('graph_template_input', ['id' => $id, 'name' => '0', 'graph_template_id' => 10]);
            $this->database->insert('snmp_query_graph', ['id' => $id, 'name' => '0', 'snmp_query_id' => 10]);
            $this->database->insert('snmp_query', ['id' => $id, 'name' => '0']);
            $this->database->insert('graph_templates', ['id' => $id, 'name' => '0']);
            $this->database->insert('snmpagent_mibs', ['id' => $id, 'name' => $id === 4 ? '0' : 'other', 'file' => 'private-canary']);
        }
        $this->database->insert('graph_templates_item', ['id' => 7, 'graph_template_id' => 10, 'text_format' => 'item']);
        $this->database->insert('graph_template_input_defs', ['graph_template_input_id' => 4, 'graph_template_item_id' => 7]);
        $this->database->insert('data_template', ['id' => 10, 'name' => 'data template']);
        $this->database->insert('data_template_rrd', ['id' => 7, 'data_template_id' => 10, 'data_source_name' => 'source']);
        $this->database->insert('snmp_query_graph_rrd', ['snmp_query_graph_id' => 4, 'data_template_id' => 10, 'data_template_rrd_id' => 7, 'snmp_field_name' => 'field']);
        $this->database->insert('snmp_query_graph_sv', ['id' => 7, 'snmp_query_graph_id' => 4, 'field_name' => 'same', 'text' => '|query_ifName|,0,IF']);
        $this->database->insert('snmp_query_graph_rrd_sv', ['id' => 7, 'snmp_query_graph_id' => 4, 'data_template_id' => 10, 'field_name' => 'name', 'text' => '|query_ifName|']);
        $this->database->insert('host_template', ['id' => 7, 'name' => 'device template']);
        $this->database->insert('host_template_graph', ['host_template_id' => 7, 'graph_template_id' => 4]);
        $this->database->insert('host_template_snmp_query', ['host_template_id' => 7, 'snmp_query_id' => 4]);
        $this->database->insert('snmpagent_cache', ['oid' => '1.2.3', 'name' => 'object', 'mib' => '0', 'description' => 'description']);
        $this->database->insert('snmpagent_cache', ['oid' => '1.2.4', 'name' => 'object', 'mib' => 'foreign', 'description' => 'private-canary']);
        $this->database->insert('snmpagent_cache_textual_conventions', ['name' => 'convention', 'mib' => '0', 'type' => 'A', 'description' => 'description']);
        $this->database->insert('snmpagent_cache_notifications', ['name' => 'notify', 'mib' => '0', 'attribute' => 'attribute', 'sequence_id' => 1]);
    }
}
