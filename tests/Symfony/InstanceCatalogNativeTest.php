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
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\Navigation\Contract\Management\CatalogFilters;
use Kadupul\Navigation\Contract\Management\CatalogPage;
use Kadupul\Navigation\Contract\Management\CatalogParentNotFound;
use Kadupul\Navigation\Contract\Management\CatalogScreen;
use PHPUnit\Framework\TestCase;

final class InstanceCatalogNativeTest extends TestCase
{
    private const array TABLES = ['reports_items', 'graph_tree', 'graph_tree_items', 'poller', 'automation_networks', 'automation_devices', 'version', 'poller_output_boost', 'settings', 'user_auth', 'user_auth_perms', 'user_auth_realm', 'user_auth_group', 'user_auth_group_members', 'user_auth_group_perms', 'user_auth_group_realm', 'host', 'host_template', 'host_template_graph', 'graph_local', 'graph_templates', 'graph_templates_graph', 'graph_templates_item', 'graph_template_input', 'graph_template_input_defs', 'data_local', 'data_template', 'data_template_data', 'data_template_rrd', 'data_source_profiles', 'aggregate_graphs', 'aggregate_graphs_items', 'aggregate_graph_templates', 'reports', 'plugin_config', 'snmp_query', 'snmp_query_graph', 'snmp_query_graph_rrd', 'cdef', 'vdef'];
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
        $visibility = new \Kadupul\IdentityAccess\Infrastructure\Persistence\DbalManagementVisibility($this->database);
        $this->catalogs = [new \Kadupul\Graphing\Infrastructure\Persistence\DbalInstanceCatalog($this->database, $visibility), new \Kadupul\Reporting\Infrastructure\Persistence\DbalReportItemCatalog($this->database), new \Kadupul\Platform\Infrastructure\Persistence\DbalCollectorDeviceCatalog($this->database, $visibility), new \Kadupul\Automation\Infrastructure\Persistence\DbalNetworkDeviceCatalog($this->database)];
        $this->database->executeStatement("INSERT INTO version (cacti) VALUES ('1.3.0')");
        $this->database->executeStatement("INSERT INTO settings (name,value) VALUES ('auth_method','1'),('graph_auth_method','3')");
        $this->database->executeStatement("INSERT INTO user_auth (id,username,enabled,policy_graphs,policy_hosts,policy_graph_templates) VALUES (42,'operator','on',2,2,2),(43,'other','on',2,2,2)");
        $this->database->executeStatement("INSERT INTO host (id,description,hostname,disabled,snmp_community) VALUES (1,'Visible','one.test','','credential-canary'),(2,'Forbidden','two.test','','credential-canary'),(3,'Disabled','three.test','on','credential-canary')");
        $this->database->executeStatement("INSERT INTO graph_templates (id,name) VALUES (10,'Template <b>'),(20,'Other template')");
        $this->database->executeStatement("INSERT INTO host_template (id,name) VALUES (4,'Device template')");
        $this->database->executeStatement('INSERT INTO host_template_graph VALUES (4,10)');
        $this->database->executeStatement('INSERT INTO graph_local (id,host_id,graph_template_id) VALUES (101,1,10),(102,2,10),(103,3,20),(104,0,0),(201,0,0)');
        $this->database->executeStatement("INSERT INTO graph_templates_graph (local_graph_id,graph_template_id,title_cache,height,width) VALUES (101,10,'Visible graph',100,200),(102,10,'Forbidden graph',100,200),(103,20,'Disabled graph',100,200),(104,0,'Non-device graph',100,200),(201,0,'Aggregate <b>',100,200),(0,10,'Template-level metadata',111,222),(0,20,'Other metadata',333,444)");
        $this->database->executeStatement('INSERT INTO user_auth_perms (user_id,item_id,type) VALUES (42,101,1),(42,104,1),(42,201,1),(42,1,3),(42,3,3)');
        $this->database->executeStatement('INSERT INTO data_local (id,host_id,data_template_id) VALUES (1001,1,50),(1002,2,50),(1003,3,50),(1004,0,0)');
        $this->database->executeStatement("INSERT INTO data_template (id,name) VALUES (50,'Data template')");
        $this->database->executeStatement("INSERT INTO data_template_data (local_data_id,data_template_id,name_cache,active,rrd_step) VALUES (1001,50,'Visible source','on',300),(1002,50,'Forbidden source','on',300),(1003,50,'Disabled source','',300),(1004,0,'Non-device source','on',300)");
        $this->database->executeStatement("INSERT INTO data_template_rrd (id,local_data_id,data_template_id,data_source_name) VALUES (1,0,50,'traffic')");
        $this->database->executeStatement("INSERT INTO graph_templates_item (id,local_graph_id,graph_template_id,text_format,sequence,task_item_id) VALUES (31,0,10,'Template item',1,1),(32,0,20,'Other item',1,1),(33,101,10,'Local instance',1,1)");
        $this->database->executeStatement("INSERT INTO graph_template_input (id,graph_template_id,name,column_name) VALUES (41,10,'Input','text_format'),(42,20,'Other input','text_format')");
        $this->database->executeStatement('INSERT INTO graph_template_input_defs VALUES (41,31),(41,32),(41,33)');
        $this->database->executeStatement("INSERT INTO snmp_query (id,name,xml_path) VALUES (4,'Interfaces','test.xml')");
        $this->database->executeStatement("INSERT INTO snmp_query_graph (id,snmp_query_id,name,graph_template_id) VALUES (51,4,'Traffic',10)");
        $this->database->executeStatement('INSERT INTO snmp_query_graph_rrd (snmp_query_graph_id,data_template_id,data_template_rrd_id) VALUES (51,50,1),(51,50,2)');
        $this->database->executeStatement("INSERT INTO aggregate_graph_templates (id,name,graph_template_id,gprint_prefix,graph_type,total,total_type,total_prefix,order_type,user_id) VALUES (1,'Aggregate template',10,'',1,0,0,'',1,42)");
        $this->database->executeStatement("INSERT INTO aggregate_graphs (id,aggregate_template_id,local_graph_id,title_format,graph_template_id,gprint_prefix,graph_type,total,total_type,total_prefix,order_type,user_id) VALUES (1,1,201,'Aggregate',10,'',1,0,0,'',1,42)");
        $this->database->executeStatement('INSERT INTO aggregate_graphs_items VALUES (1,101,1),(1,103,2)');
        $this->database->executeStatement("INSERT INTO reports (id,user_id,name,from_name,from_email,email,bcc,enabled) VALUES (1,42,'Mine','Operator','credential-canary','credential-canary','credential-canary','on'),(2,43,'Theirs','Other','','','','on'),(3,999,'Orphan','','','','','on')");
        $this->database->executeStatement("INSERT INTO plugin_config (id,directory,name,status,author,version,webpage) VALUES (1,'example','Plugin <b>',1,'Author','1.0','javascript:credential-canary')");
    }

    private function seedInstances(): void
    {
        $this->database->executeStatement("INSERT INTO data_template_rrd (id,local_data_id,data_template_id,data_source_name,rrd_minimum,rrd_maximum) VALUES (501,1001,50,'in','-1','1e9'),(502,1002,50,'private','0','U')");
        $this->database->executeStatement("INSERT INTO graph_templates_item (id,local_graph_id,graph_template_id,text_format,sequence,task_item_id) VALUES (331,101,10,'First <b>',2,501),(332,101,10,'Second',3,501),(333,101,10,'Hidden source',4,502),(334,102,10,'Hidden graph',1,501),(335,104,0,'Non-device',1,501)");
        $this->database->executeStatement('INSERT INTO aggregate_graphs_items VALUES (1,102,3)');
        $this->database->executeStatement("INSERT INTO reports_items (id,report_id,item_type,item_text,sequence) VALUES (1,1,3,'Literal <script>',2),(2,2,3,'Other owner',1)");
        $this->database->executeStatement("INSERT INTO graph_tree (id,name) VALUES (4,'Tree'),(5,'Other tree')");
        $this->database->executeStatement("INSERT INTO graph_tree_items (id,graph_tree_id,title,parent,position,local_graph_id,host_id) VALUES (4294967296,4,'Heading',NULL,1,0,0),(2,5,'Other tree',0,1,0,0)");
        $this->database->executeStatement("INSERT INTO poller (id,name) VALUES (4,'Collector'),(5,'Other collector')");
        $this->database->executeStatement('UPDATE host SET poller_id=4 WHERE id IN (1,2)');
        $this->database->executeStatement("INSERT INTO automation_networks (id,name) VALUES (4,'Network'),(5,'Other network')");
        $this->database->executeStatement("INSERT INTO automation_devices (id,network_id,hostname,ip,sysName,os,snmp_password) VALUES (1,4,'discovered','192.0.2.1','Router:one','OS:one','credential-canary'),(2,5,'elsewhere','192.0.2.2','Other network','Other OS','credential-canary')");
    }

    public function testAllTenReadersSupportEverySortWithBoundedQueriesAndExplicitPublicColumns(): void
    {
        $this->seedInstances();
        $count = 0;
        foreach (CatalogScreen::cases() as $screen) {
            if (!$screen->instanceView()) {
                continue;
            }
            ++$count;
            foreach (array_keys($screen->columns()) as $column) {
                foreach (['ASC', 'DESC'] as $direction) {
                    $this->queries = 0;
                    $page = $this->read($screen, ['sort_column' => $column, 'sort_direction' => $direction]);
                    self::assertNotEmpty($page->records, $screen->value . ':' . $column);
                    self::assertLessThanOrEqual(5, $this->queries, 'No per-row queries: ' . $screen->value);
                    foreach ($page->records as $row) {
                        self::assertEqualsCanonicalizing(array_keys($screen->columns()), array_keys($row));
                        self::assertStringNotContainsString('credential-canary', json_encode($row, JSON_THROW_ON_ERROR));
                    }
                }
            }
            self::assertSame([], $this->read($screen, ['filter' => "' OR 1=1 --"])->records);
        }
        self::assertSame(10, $count);
    }

    public function testGraphSourceAndAggregateRelationshipsApplyBothParentAndChildVisibility(): void
    {
        $this->seedInstances();
        self::assertSame([1001], $this->ids(CatalogScreen::GraphSources));
        self::assertSame([104, 101], $this->ids(CatalogScreen::SourceGraphs));
        self::assertSame([101, 103], $this->ids(CatalogScreen::AggregateMembers));
        self::assertSame([501], $this->ids(CatalogScreen::SourceDefinitions));
        self::assertSame([1], $this->ids(CatalogScreen::CollectorDevices));
        foreach ([CatalogScreen::LocalGraphItems, CatalogScreen::GraphSources, CatalogScreen::SourceDefinitions, CatalogScreen::SourceGraphs] as $screen) {
            try {
                $this->read($screen, [], in_array($screen, [CatalogScreen::LocalGraphItems, CatalogScreen::GraphSources], true) ? 102 : 1002);
                self::fail('A forbidden parent cannot become an empty relationship list.');
            } catch (CatalogParentNotFound) {
                self::assertTrue(true);
            }
        }
        // Revoke between reads: the reader must not reuse a previous policy snapshot.
        $this->database->executeStatement('UPDATE data_local SET host_id=3 WHERE id=1001');
        $this->database->executeStatement('DELETE FROM user_auth_perms WHERE user_id=42 AND type=3 AND item_id=3');
        self::assertSame([], $this->ids(CatalogScreen::GraphSources));
        $this->database->executeStatement('DELETE FROM user_auth_perms WHERE user_id=42 AND ((type=3 AND item_id=1) OR (type=1 AND item_id=101))');
        self::assertSame([], $this->ids(CatalogScreen::CollectorDevices));
    }

    public function testPersonalReportOwnershipIsCheckedBeforeReadingAnyItems(): void
    {
        $this->seedInstances();
        self::assertSame([1], $this->ids(CatalogScreen::MyReportItems));
        self::assertSame('Literal <script>', $this->read(CatalogScreen::MyReportItems)->records[0]['name']);
        self::assertSame([2], $this->ids(CatalogScreen::ReportItems, 2));
        $this->queries = 0;
        try {
            $this->read(CatalogScreen::MyReportItems, [], 2);
            self::fail('Another account report must appear missing.');
        } catch (CatalogParentNotFound) {
            self::assertSame(1, $this->queries);
        }
    }

    public function testTreeAndDiscoveryParentsPreserveStoredIdentitiesAndExcludeOtherOwners(): void
    {
        $this->seedInstances();
        self::assertSame([4294967296], $this->ids(CatalogScreen::TreeItems));
        self::assertNull($this->read(CatalogScreen::TreeItems)->records[0]['parent']);
        self::assertSame([1], $this->ids(CatalogScreen::NetworkDevices));
        self::assertSame('Router one', $this->read(CatalogScreen::NetworkDevices)->records[0]['name']);
        foreach ([CatalogScreen::TreeItems, CatalogScreen::CollectorDevices, CatalogScreen::NetworkDevices, CatalogScreen::ReportItems] as $screen) {
            try {
                $this->read($screen, [], 999);
                self::fail('Missing parent must be distinguished from an empty child list.');
            } catch (CatalogParentNotFound) {
                self::assertTrue(true);
            }
        }
        $this->database->executeStatement('DELETE FROM reports_items WHERE report_id=1');
        self::assertSame([], $this->read(CatalogScreen::ReportItems)->records);
        $this->database->executeStatement('DROP TABLE reports_items');
        $this->expectException(\Doctrine\DBAL\Exception::class);
        $this->read(CatalogScreen::ReportItems);
    }

    public function testMaximumPageHasALookaheadWithoutDuplicateRowsFromMultipleGraphItems(): void
    {
        $this->seedInstances();
        for ($id = 10; $id < 111; ++$id) {
            $this->database->insert('reports_items', ['id' => $id, 'report_id' => 1, 'item_text' => 'Same name', 'sequence' => $id]);
        }
        $first = $this->read(CatalogScreen::ReportItems, ['rows' => '100', 'sort_column' => 'name']);
        self::assertCount(101, $first->records);
        $second = $this->read(CatalogScreen::ReportItems, ['rows' => '100', 'page' => '2', 'sort_column' => 'name']);
        self::assertCount(2, $second->records);
        self::assertSame([], array_intersect(array_column(array_slice($first->records, 0, 100), 'id'), array_column($second->records, 'id')));
        self::assertCount(1, $this->read(CatalogScreen::GraphSources)->records);
    }

    public function testNullableGraphLabelsAndNumericLookingNamesRemainVisible(): void
    {
        $this->seedInstances();
        $this->database->executeStatement('INSERT INTO graph_templates_item (id, local_graph_id, text_format, value) VALUES (336,101,NULL,NULL)');
        $rows = $this->read(CatalogScreen::LocalGraphItems)->records;
        $unnamed = array_find($rows, static fn(array $row): bool => (int) $row['id'] === 336);
        self::assertNotNull($unnamed);
        self::assertNull($unnamed['name']);
        self::assertNull($unnamed['value']);
        $this->database->executeStatement('UPDATE poller SET name=NULL WHERE id=4');
        self::assertSame('', $this->read(CatalogScreen::CollectorDevices)->parentName);
        $this->database->executeStatement("UPDATE data_template_data SET name_cache='0' WHERE local_data_id=1001");
        self::assertSame('0', $this->read(CatalogScreen::SourceDefinitions)->parentName);
        self::assertSame('-1', $this->read(CatalogScreen::SourceDefinitions)->records[0]['minimum']);
        self::assertSame('1e9', $this->read(CatalogScreen::SourceDefinitions)->records[0]['maximum']);
    }

    private function read(CatalogScreen $screen, array $query = [], ?int $parentId = null): CatalogPage
    {
        $parentId ??= match ($screen) {
            CatalogScreen::LocalGraphItems, CatalogScreen::GraphSources => 101,
            CatalogScreen::SourceDefinitions, CatalogScreen::SourceGraphs => 1001,
            CatalogScreen::AggregateMembers, CatalogScreen::ReportItems, CatalogScreen::MyReportItems => 1,
            default => 4,
        };
        foreach ($this->catalogs as $catalog) {
            if ($catalog->supports($screen)) {
                return $catalog->read($screen, CatalogFilters::fromQuery($screen, $query), 42, $parentId);
            }
        }
        throw new \LogicException('Missing owning reader.');
    }

    private function ids(CatalogScreen $screen, ?int $parentId = null): array
    {
        return array_map('intval', array_column($this->read($screen, [], $parentId)->records, 'id'));
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

}
