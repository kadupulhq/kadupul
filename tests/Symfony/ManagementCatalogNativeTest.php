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
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Graphing\Infrastructure\Persistence\DbalGraphingManagementCatalog;
use Kadupul\Graphing\Infrastructure\Persistence\ManagementGraphScope;
use Kadupul\DataInput\Infrastructure\Persistence\DbalQueryGraphCatalog;
use Kadupul\Reporting\Infrastructure\Persistence\DbalReportManagementCatalog;
use Kadupul\Platform\Infrastructure\Persistence\DbalPluginManagementCatalog;
use Kadupul\Navigation\Infrastructure\Persistence\DbalManagementCatalogAccess;
use Kadupul\Navigation\Application\Query\AdministrationListDenied;
use Kadupul\Navigation\Contract\ManagementCatalog;
use Kadupul\Navigation\Contract\Management\CatalogFilters;
use Kadupul\Navigation\Contract\Management\CatalogPage;
use Kadupul\Navigation\Contract\Management\CatalogParentNotFound;
use Kadupul\Navigation\Contract\Management\CatalogScreen;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ManagementCatalogNativeTest extends TestCase
{
    private const array TABLES = ['version', 'poller_output_boost', 'settings', 'user_auth', 'user_auth_perms', 'user_auth_realm', 'user_auth_group', 'user_auth_group_members', 'user_auth_group_perms', 'user_auth_group_realm', 'host', 'host_template', 'host_template_graph', 'graph_local', 'graph_templates', 'graph_templates_graph', 'graph_templates_item', 'graph_template_input', 'graph_template_input_defs', 'data_local', 'data_template', 'data_template_data', 'data_template_rrd', 'data_source_profiles', 'aggregate_graphs', 'aggregate_graphs_items', 'aggregate_graph_templates', 'reports', 'plugin_config', 'snmp_query', 'snmp_query_graph', 'snmp_query_graph_rrd', 'cdef', 'vdef'];
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
        $this->catalogs = [new DbalGraphingManagementCatalog($this->database, new ManagementGraphScope(new \Kadupul\IdentityAccess\Infrastructure\Persistence\DbalManagementVisibility($this->database))), new DbalQueryGraphCatalog($this->database), new DbalReportManagementCatalog($this->database), new DbalPluginManagementCatalog($this->database)];
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

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
        if ($this->admin !== null && $this->schema !== '') {
            $this->admin->exec('DROP DATABASE IF EXISTS `' . $this->schema . '`');
        }
    }

    public function testEveryScreenSortsEveryPublicColumnInBothDirectionsOnTheActualSchema(): void
    {
        foreach (CatalogScreen::cases() as $screen) {
            if ($screen->metadataView() || $screen->instanceView()) {
                continue;
            }
            foreach (array_keys($screen->columns()) as $column) {
                foreach (['ASC','DESC'] as $direction) {
                    $this->queries = 0;
                    $page = $this->read($screen, ['sort_column' => $column,'sort_direction' => $direction]);
                    self::assertNotEmpty($page->records, $screen->value . ':' . $column);
                    self::assertLessThanOrEqual($screen->scoped() ? 2 : 4, $this->queries, 'No per-row queries: ' . $screen->value);
                    foreach ($page->records as $record) {
                        self::assertEqualsCanonicalizing(array_keys($screen->columns()), array_keys($record));
                        self::assertStringNotContainsString('credential-canary', json_encode($record, JSON_THROW_ON_ERROR));
                    }
                }
            }
        }
    }

    public function testResourceScopesAndTemplateIdentitiesPreventCrossRecordLeaks(): void
    {
        self::assertSame([103,104,101], array_map('intval', array_column($this->read(CatalogScreen::Graphs)->records, 'id')));
        self::assertSame([1003,1004,1001], array_map('intval', array_column($this->read(CatalogScreen::DataSources)->records, 'id')));
        self::assertSame([31], array_map('intval', array_column($this->read(CatalogScreen::GraphItems)->records, 'id')));
        self::assertSame(1, (int) $this->read(CatalogScreen::GraphInputs)->records[0]['items']);
        self::assertSame('111x222', $this->read(CatalogScreen::DeviceGraphs)->records[0]['size']);
        self::assertSame(2, (int) $this->read(CatalogScreen::QueryGraphs)->records[0]['data_sources']);
        self::assertSame(2, (int) $this->read(CatalogScreen::Aggregates)->records[0]['members']);
        $this->database->executeStatement('INSERT INTO graph_local (id,host_id,graph_template_id) VALUES (999,999,10)');
        $this->database->executeStatement("INSERT INTO graph_templates_graph (local_graph_id,graph_template_id,title_cache) VALUES (999,10,'Missing device')");
        $this->database->executeStatement('INSERT INTO user_auth_perms (user_id,item_id,type) VALUES (42,999,1)');
        $scope = (new ManagementGraphScope(new \Kadupul\IdentityAccess\Infrastructure\Persistence\DbalManagementVisibility($this->database)))->forActor(42);
        self::assertContains(999, array_map('intval', $this->database->fetchFirstColumn($scope['graphs'])));
        self::assertNotContains(999, array_map('intval', array_column($this->read(CatalogScreen::Graphs)->records, 'id')), 'A permitted graph still needs an existing permitted device, or host_id=0.');
        $this->expectException(CatalogParentNotFound::class);
        $this->read(CatalogScreen::GraphItems, [], 999);
    }

    public function testReportsRemainOwnerScopedAndOrphanDisplayDoesNotWrite(): void
    {
        self::assertSame([1], array_map('intval', array_column($this->read(CatalogScreen::MyReports)->records, 'id')));
        $all = $this->read(CatalogScreen::AdminReports)->records;
        self::assertCount(3, $all);
        $orphan = array_find($all, static fn(array $record): bool => (int) $record['id'] === 3);
        self::assertSame('', $orphan['enabled']);
        self::assertNull($orphan['owner']);
        self::assertSame('on', $this->database->fetchOne('SELECT enabled FROM reports WHERE id=3'));
        $this->database->executeStatement('INSERT INTO user_auth_realm (user_id,realm_id) VALUES (42,21)');
        self::assertCount(1, $this->read(CatalogScreen::MyReports)->records, 'Personal scope remains explicit even for an administrator.');
    }

    public function testNewVisibilityPredicatesMatchTheRealLegacyProducerAcrossPolicies(): void
    {
        foreach ([1,2,3,4] as $mode) {
            foreach ([1,2] as $default) {
                $this->database->executeStatement("UPDATE settings SET value=? WHERE name='graph_auth_method'", [(string) $mode]);
                $this->database->executeStatement('UPDATE user_auth SET policy_graphs=?,policy_hosts=?,policy_graph_templates=? WHERE id=42', [$default,$default,$default]);
                $scope = (new ManagementGraphScope(new \Kadupul\IdentityAccess\Infrastructure\Persistence\DbalManagementVisibility($this->database)))->forActor(42);
                $actual = [];
                foreach ($scope as $kind => $sql) {
                    $actual[$kind] = array_map('intval', $this->database->fetchFirstColumn($sql));
                    sort($actual[$kind]);
                }
                $process = new Process([PHP_BINARY, dirname(__DIR__) . '/Fixtures/management-legacy-scope.php'], dirname(__DIR__, 2), ['KADUPUL_MANAGEMENT_TEST_SCHEMA' => $this->schema]);
                $process->setTimeout(15);
                $process->mustRun();
                $expected = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                foreach ($expected as &$ids) {
                    $ids = array_map('intval', $ids);
                    sort($ids);
                } unset($ids);
                self::assertSame($expected, $actual, 'mode=' . $mode . ', default=' . $default);
            }
        }
    }

    public function testResourceGrantsFollowEnabledGroupsWithoutCachingPreviousResults(): void
    {
        $this->database->executeStatement('DELETE FROM user_auth_perms WHERE user_id=42');
        $this->database->executeStatement("INSERT INTO user_auth_group (id,name,enabled,policy_graphs,policy_hosts,policy_graph_templates) VALUES (1,'Operators','on',2,2,2)");
        $this->database->executeStatement('INSERT INTO user_auth_group_members (group_id,user_id) VALUES (1,42)');
        $this->database->executeStatement('INSERT INTO user_auth_group_perms (group_id,item_id,type) VALUES (1,102,1),(1,2,3)');
        self::assertSame([102], array_map('intval', array_column($this->read(CatalogScreen::Graphs)->records, 'id')));
        $this->database->executeStatement("UPDATE user_auth_group SET enabled='' WHERE id=1");
        self::assertSame([], $this->read(CatalogScreen::Graphs)->records);
        self::assertSame([1004], array_map('intval', array_column($this->read(CatalogScreen::DataSources)->records, 'id')));
    }

    public function testFreshFeatureAdmissionRequiresConsoleAndMatchingDirectOrEnabledGroupRealms(): void
    {
        $console = $this->createStub(ConsoleAccess::class);
        $console->method('consoleActor')->willReturn(new Actor(42, 'operator'));
        $identity = $this->createStub(\Kadupul\IdentityAccess\Contract\AuthenticatedAccess::class);
        $identity->method('authenticatedActor')->willReturn(new Actor(42, 'operator'));
        $access = new DbalManagementCatalogAccess($console, $this->database, $identity);
        foreach (CatalogScreen::cases() as $screen) {
            $this->database->executeStatement('DELETE FROM user_auth_realm WHERE user_id=42');
            $this->deny($access, $screen);
            $this->database->executeStatement('INSERT INTO user_auth_realm (user_id,realm_id) VALUES (42,?)', [$screen->realm()]);
            if ($screen->personalReport()) {
                self::assertSame(42, $access->authorize($screen)->id);
            } else {
                $this->deny($access, $screen);
            }
            $this->database->executeStatement('INSERT INTO user_auth_realm (user_id,realm_id) VALUES (42,8)');
            foreach ($screen->relatedRealms() as $realm) {
                $this->deny($access, $screen);
                $this->database->executeStatement('INSERT INTO user_auth_realm (user_id,realm_id) VALUES (42,?)', [$realm]);
            }
            self::assertSame(42, $access->authorize($screen)->id);
        }
        foreach (["enabled=''","locked='on'","must_change_password='on'"] as $mutation) {
            $this->database->executeStatement('UPDATE user_auth SET ' . $mutation . ' WHERE id=42');
            $this->deny($access, CatalogScreen::QueryGraphs);
            $this->database->executeStatement("UPDATE user_auth SET enabled='on',locked='',must_change_password='' WHERE id=42");
        }
        $this->database->executeStatement("INSERT INTO settings (name,value) VALUES ('guest_user','operator')");
        $this->deny($access, CatalogScreen::QueryGraphs);
        $this->database->executeStatement("DELETE FROM settings WHERE name='guest_user'");
        $this->database->executeStatement('DELETE FROM user_auth_realm WHERE user_id=42');
        $this->database->executeStatement("INSERT INTO user_auth_group (id,name,enabled) VALUES (1,'Operators','on')");
        $this->database->executeStatement('INSERT INTO user_auth_group_members (group_id,user_id) VALUES (1,42)');
        $this->database->executeStatement('INSERT INTO user_auth_group_realm (group_id,realm_id) VALUES (1,8),(1,13)');
        self::assertSame(42, $access->authorize(CatalogScreen::QueryGraphs)->id);
        $this->deny($access, CatalogScreen::QueryDeviceTemplates);
        $this->database->executeStatement('INSERT INTO user_auth_group_realm (group_id,realm_id) VALUES (1,12)');
        self::assertSame(42, $access->authorize(CatalogScreen::QueryDeviceTemplates)->id);
        $this->database->executeStatement('DELETE FROM user_auth_group_realm WHERE group_id=1 AND realm_id=12');
        $this->deny($access, CatalogScreen::QueryDeviceTemplates);
        self::assertSame(42, $access->authorize(CatalogScreen::QueryGraphs)->id);
        $this->database->executeStatement("UPDATE user_auth_group SET enabled='' WHERE id=1");
        $this->deny($access, CatalogScreen::QueryGraphs);
    }

    public function testDuplicateNamePagingIsStableAndFailedReadsDoNotLookEmpty(): void
    {
        for ($id = 10;$id < 35;$id++) {
            $this->database->executeStatement("INSERT INTO plugin_config (id,name,directory) VALUES (?,'Same name',?)", [$id,'plugin' . $id]);
        }
        $one = $this->read(CatalogScreen::Plugins, ['filter' => 'Same name','rows' => '10']);
        $two = $this->read(CatalogScreen::Plugins, ['filter' => 'Same name','rows' => '10','page' => '2']);
        self::assertCount(11, $one->records);
        self::assertCount(11, $two->records);
        self::assertSame([20], array_values(array_intersect(array_column($one->records, 'id'), array_column($two->records, 'id'))), 'Only the lookahead row overlaps.');
        self::assertSame([], $this->read(CatalogScreen::Plugins, ['filter' => "' OR 1=1 --"])->records);
        $this->database->executeStatement('DROP TABLE plugin_config');
        $this->expectException(\Doctrine\DBAL\Exception::class);
        $this->read(CatalogScreen::Plugins);
    }

    public function testOnlineCollectorsSelectTheVerifiedPrimaryForEveryCatalogRoute(): void
    {
        $directory = sys_get_temp_dir() . '/diagnostic-primary-' . bin2hex(random_bytes(8));
        mkdir($directory . '/include', 0700, true);
        $localSchema = 'diagnostic_local_' . bin2hex(random_bytes(8));
        $this->admin->exec('CREATE DATABASE `' . $localSchema . '`');
        foreach (['version', 'poller_output_boost'] as $table) {
            $this->admin->exec('CREATE TABLE `' . $localSchema . '`.`' . $table . '` LIKE `' . $this->schema . '`.`' . $table . '`');
        }
        $this->admin->exec("INSERT INTO `$localSchema`.version (cacti) VALUES ('1.3.0')");
        $parameters = $this->database->getParams();
        $source = "<?php\n\$poller_id = 2;\n";
        foreach (['database' => 'local', 'rdatabase' => 'primary'] as $prefix => $label) {
            foreach (['hostname' => $parameters['host'], 'port' => $parameters['port'], 'default' => $prefix === 'database' ? $localSchema : $this->schema, 'username' => $parameters['user'], 'password' => $parameters['password']] as $key => $value) {
                $source .= '$' . $prefix . '_' . $key . ' = ' . var_export($value, true) . ";\n";
            }
        }
        file_put_contents($directory . '/include/config.php', $source);
        try {
            foreach (CatalogScreen::cases() as $screen) {
                $request = \Symfony\Component\HttpFoundation\Request::create('/utilities/system');
                $request->attributes->set('_route', $screen->route());
                $requests = new \Symfony\Component\HttpFoundation\RequestStack();
                $requests->push($request);
                $configuration = new \Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration($directory, $requests);
                self::assertSame(2, $configuration->values()['collector_id']);
                self::assertSame($this->schema, $configuration->values()['database']);
                self::assertSame($parameters['host'], $configuration->values()['host']);
            }
            $this->admin->exec("INSERT INTO `$localSchema`.poller_output_boost (local_data_id, rrd_name, time, output) VALUES (1, 'test', '2026-10-26 00:00:00', '1')");
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Sites administration requires an online collector with a reachable primary.');
            (new \Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration($directory, $requests))->values();
        } finally {
            $this->admin->exec('DROP DATABASE IF EXISTS `' . $localSchema . '`');
            unlink($directory . '/include/config.php');
            rmdir($directory . '/include');
            rmdir($directory);
        }
    }

    private function read(CatalogScreen $screen, array $query = [], int $parent = 4): CatalogPage
    {
        if (in_array($screen, [CatalogScreen::GraphItems,CatalogScreen::GraphInputs], true) && $parent === 4) {
            $parent = 10;
        }
        foreach ($this->catalogs as $catalog) {
            if ($catalog->supports($screen)) {
                return $catalog->read($screen, CatalogFilters::fromQuery($screen, $query), 42, $screen->scoped() ? $parent : 0);
            }
        }
        throw new \LogicException('Missing native catalog.');
    }

    private function deny(DbalManagementCatalogAccess $access, CatalogScreen $screen): void
    {
        try {
            $access->authorize($screen);
            self::fail('Expected denied admission.');
        } catch (AdministrationListDenied $error) {
            self::assertFalse($error->unauthenticated);
        }
    }
}
