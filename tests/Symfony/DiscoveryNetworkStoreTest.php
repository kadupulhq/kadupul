<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Query\AutomationTemplateAccessDenied;
use Kadupul\Automation\Domain\DiscoveryNetworkChange;
use Kadupul\Automation\Domain\DiscoveryNetworkRejected;
use Kadupul\Automation\Infrastructure\Persistence\DbalDiscoveryNetworkStore;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;

final class DiscoveryNetworkStoreTest extends TestCase
{
    private const array FIELDS = ['name' => 'Core', 'poller_id' => 1, 'site_id' => 0, 'subnet_range' => '192.168.1.0/24', 'dns_servers' => '',
        'enabled' => true, 'enable_netbios' => false, 'add_to_cacti' => true, 'same_sysname' => false, 'rerun_data_queries' => false,
        'notification_enabled' => false, 'notification_email' => '', 'notification_fromname' => '', 'notification_fromemail' => '',
        'snmp_id' => 1, 'ping_method' => 2, 'ping_port' => 23, 'ping_timeout' => 400, 'ping_retries' => 1,
        'sched_type' => 1, 'threads' => 1, 'run_limit' => 1200, 'start_at' => '2026-11-09 10:00', 'recur_every' => 1,
        'day_of_week' => [], 'month' => [], 'day_of_month' => [], 'monthly_week' => [], 'monthly_day' => []];

    private Connection $database;
    private DbalDiscoveryNetworkStore $store;
    private bool $revoked = false;
    /** @var list<bool> */
    private array $checkedInTransaction = [];
    /** @var list<AuditEvent> */
    private array $audit = [];

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        // The cacti.sql columns, with their nullability and defaults.
        foreach ([
            "CREATE TABLE automation_networks (id INTEGER PRIMARY KEY AUTOINCREMENT, poller_id INT DEFAULT 1, site_id INT DEFAULT 1, name TEXT NOT NULL DEFAULT '',
                subnet_range TEXT NOT NULL DEFAULT '', dns_servers TEXT NOT NULL DEFAULT '', enabled TEXT DEFAULT '', notification_enabled TEXT DEFAULT '',
                notification_email TEXT DEFAULT '', notification_fromname TEXT DEFAULT '', notification_fromemail TEXT DEFAULT '', snmp_id INT DEFAULT NULL,
                enable_netbios TEXT DEFAULT '', add_to_cacti TEXT DEFAULT '', same_sysname TEXT DEFAULT '', total_ips INT DEFAULT 0, up_hosts INT NOT NULL DEFAULT 0,
                snmp_hosts INT NOT NULL DEFAULT 0, ping_method INT NOT NULL DEFAULT 0, ping_port INT NOT NULL DEFAULT 0, ping_timeout INT NOT NULL DEFAULT 0,
                ping_retries INT DEFAULT 0, sched_type INT NOT NULL DEFAULT 0, threads INT DEFAULT 1, run_limit INT DEFAULT 0, start_at TEXT DEFAULT NULL,
                next_start TEXT NOT NULL DEFAULT '0000-00-00 00:00:00', recur_every INT DEFAULT 1, day_of_week TEXT DEFAULT NULL, month TEXT DEFAULT NULL,
                day_of_month TEXT DEFAULT NULL, monthly_week TEXT DEFAULT NULL, monthly_day TEXT DEFAULT NULL, last_runtime REAL NOT NULL DEFAULT 0,
                last_started TEXT NOT NULL DEFAULT '0000-00-00 00:00:00', last_status TEXT NOT NULL DEFAULT '', rerun_data_queries TEXT DEFAULT NULL)",
            // The network cacti.sql seeds.
            "INSERT INTO automation_networks VALUES (1,1,0,'Test Network','192.168.1.0/24','','on','','','','',1,'on','on','',254,0,0,1,22,400,1,2,10,1200,
                '0000-00-00 00:00:00','0000-00-00 00:00:00',2,'4','','','','',0,'0000-00-00 00:00:00','','on')",
            'CREATE TABLE poller (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE sites (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE automation_snmp (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)',
            "INSERT INTO poller VALUES (1, 'Main Poller'), (2, 'Edge')",
            "INSERT INTO sites VALUES (1, 'Edge'), (3, 'Core')",
            "INSERT INTO automation_snmp VALUES (2, 'Zeta'), (1, 'Default Option Set')",
        ] as $statement) {
            $this->database->executeStatement($statement);
        }
        $access = $this->createMock(AutomationTemplateAccess::class);
        $access->method('assertCurrent')->willReturnCallback(function (): void {
            $this->checkedInTransaction[] = $this->database->isTransactionActive();
            if ($this->revoked) {
                throw new AutomationTemplateAccessDenied(false);
            }
        });
        $audit = $this->createMock(AuditTrail::class);
        $audit->method('record')->willReturnCallback(function (AuditEvent $event): void {
            $this->audit[] = $event;
        });
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);
        $this->store = new DbalDiscoveryNetworkStore($this->database, $access, $audit, $configuration);
    }

    public function testTheSeededNetworkLoadsAndSavesUnchanged(): void
    {
        $network = $this->store->find(1);
        self::assertNotNull($network);
        self::assertSame(['Test Network', 254], [$network->name, $network->totalIps]);
        self::assertSame([true, true, false, 0, 1, 2, 10, 1200, '0000-00-00 00:00:00', 2, [4], []], [$network->settings['enabled'], $network->settings['add_to_cacti'],
            $network->settings['notification_enabled'], $network->settings['site_id'], $network->settings['snmp_id'], $network->settings['sched_type'],
            $network->settings['threads'], $network->settings['run_limit'], $network->settings['start_at'], $network->settings['recur_every'],
            $network->settings['day_of_week'], $network->settings['month']]);
        $before = $this->database->fetchAssociative('SELECT * FROM automation_networks WHERE id = 1');
        // An untouched form validates and leaves every stored value as it was.
        self::assertSame(1, $this->store->save(7, 1, new DiscoveryNetworkChange($network->settings), $network->revision));
        self::assertEquals($before, $this->database->fetchAssociative('SELECT * FROM automation_networks WHERE id = 1'));
        self::assertNull($this->store->find(2));
        self::assertSame('automation.discovery_network.edit', $this->audit[0]->action);
        self::assertSame([true], $this->checkedInTransaction);
    }

    public function testNetworksAreCreatedInTheLegacyStorageFormats(): void
    {
        $id = $this->store->save(7, null, new DiscoveryNetworkChange([
            'name' => '  Branch <b>  ', 'site_id' => 3, 'subnet_range' => " 10.1.0.0/24 ,\n10.2.*.*", 'sched_type' => 5, 'month' => [12, 1, 1],
            'monthly_week' => [32, 1], 'monthly_day' => [2], 'notification_enabled' => true, 'notification_email' => 'noc@example.net',
            'notification_fromemail' => 'kadupul@example.net', 'dns_servers' => '192.0.2.53',
        ] + self::FIELDS), null);
        $row = $this->database->fetchAssociative('SELECT * FROM automation_networks WHERE id = ?', [$id]);
        self::assertSame(['Branch <b>', '10.1.0.0/24,10.2.*.*', '65788', 'on', '', 'on', '1,12', '1,32', '2', '', '3', '5', '192.0.2.53', '0000-00-00 00:00:00'], [
            $row['name'], $row['subnet_range'], (string) $row['total_ips'], $row['enabled'], $row['enable_netbios'], $row['notification_enabled'], $row['month'],
            $row['monthly_week'], $row['monthly_day'], $row['day_of_week'], (string) $row['site_id'], (string) $row['sched_type'], $row['dns_servers'], $row['next_start'],
        ]);
        self::assertSame('automation.discovery_network.create', $this->audit[0]->action);
    }

    public function testRunStateNeitherMakesTheFormStaleNorIsOverwritten(): void
    {
        $network = $this->store->find(1);
        // poller_automation.php records a run while the form is open.
        $this->database->update('automation_networks', ['last_status' => 'Done', 'up_hosts' => 9, 'next_start' => '2026-11-10 00:00:00', 'total_ips' => 3], ['id' => 1]);
        $this->store->save(7, 1, new DiscoveryNetworkChange(['threads' => 20] + $network->settings), $network->revision);
        self::assertSame(['Done', '9', '2026-11-10 00:00:00', '20', '254'], array_map('strval', array_values($this->database->fetchAssociative(
            'SELECT last_status, up_hosts, next_start, threads, total_ips FROM automation_networks WHERE id = 1'
        ))));
        // A new start or schedule type reschedules, as legacy did.
        foreach ([['start_at' => '2026-12-01 08:30'], ['sched_type' => 3, 'day_of_week' => [2]]] as $change) {
            $this->database->update('automation_networks', ['next_start' => '2026-11-10 00:00:00'], ['id' => 1]);
            $network = $this->store->find(1);
            $this->store->save(7, 1, new DiscoveryNetworkChange($change + $network->settings), $network->revision);
            self::assertSame('0000-00-00 00:00:00', $this->database->fetchOne('SELECT next_start FROM automation_networks WHERE id = 1'), json_encode($change, JSON_THROW_ON_ERROR));
        }
        // Another administrator's edit makes the open form stale.
        $network = $this->store->find(1);
        $this->database->update('automation_networks', ['dns_servers' => '192.0.2.1'], ['id' => 1]);
        foreach ([$network->revision, null, ''] as $stale) {
            try {
                $this->store->save(7, 1, new DiscoveryNetworkChange($network->settings), $stale);
                self::fail('A stale discovery network edit was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('changed since', $error->getMessage());
            }
        }
        // A network created outside the form has no start; it opens with the current time and still saves unchanged otherwise.
        $created = (int) $this->database->executeStatement("INSERT INTO automation_networks (name, subnet_range, snmp_id, ping_method, sched_type, run_limit) VALUES ('Bare', '10.0.0.1', 1, 2, 1, 60)");
        $bare = $this->store->find((int) $this->database->lastInsertId());
        self::assertSame(1, $created);
        self::assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $bare->settings['start_at']);
        $this->store->save(7, $bare->id, new DiscoveryNetworkChange($bare->settings), $bare->revision);
        $stored = $this->database->fetchAssociative('SELECT start_at, next_start, total_ips FROM automation_networks WHERE id = ?', [$bare->id]);
        self::assertSame([$bare->settings['start_at'], '0000-00-00 00:00:00', '1'], array_map('strval', array_values((array) $stored)));
        $this->expectExceptionMessage('Discovery network not found.');
        $this->store->save(7, 9, new DiscoveryNetworkChange(self::FIELDS), $network->revision);
    }

    public function testMissingReferencesAndRevokedAccessWriteNothing(): void
    {
        $count = static fn(Connection $database): mixed => $database->fetchOne('SELECT COUNT(*) FROM automation_networks');
        foreach ([['poller_id' => 9], ['snmp_id' => 9], ['site_id' => 2]] as $change) {
            try {
                $this->store->save(7, null, new DiscoveryNetworkChange($change + self::FIELDS), null);
                self::fail('A network referencing a missing record was stored.');
            } catch (DiscoveryNetworkRejected $error) {
                self::assertSame(array_key_first($change), $error->field);
            }
        }
        $this->revoked = true;
        try {
            $this->store->save(7, null, new DiscoveryNetworkChange(self::FIELDS), null);
            self::fail('A revoked administrator created a network.');
        } catch (AutomationTemplateAccessDenied) {
        }
        self::assertSame('1', (string) $count($this->database));
    }

    public function testDefaultsComeFromTheGlobalSettings(): void
    {
        $defaults = $this->store->defaults();
        // Unset settings fall back to the global_settings.php defaults, and the first SNMP Option Set by name is preselected.
        self::assertSame([1, 1, 2, 23, 400, 1, 1, 1200, 1], [$defaults['poller_id'], $defaults['site_id'], $defaults['ping_method'], $defaults['ping_port'],
            $defaults['ping_timeout'], $defaults['ping_retries'], $defaults['snmp_id'], $defaults['run_limit'], $defaults['sched_type']]);
        $this->database->executeStatement("INSERT INTO settings VALUES ('default_poller', '2'), ('default_site', '3'), ('ping_method', '3'), ('ping_port', '70000'), ('ping_retries', 'x')");
        $defaults = $this->store->defaults();
        self::assertSame([2, 3, 3, 23, 1], [$defaults['poller_id'], $defaults['site_id'], $defaults['ping_method'], $defaults['ping_port'], $defaults['ping_retries']]);
        self::assertSame(['pollers' => [2 => 'Edge', 1 => 'Main Poller'], 'sites' => [3 => 'Core', 1 => 'Edge'], 'snmp' => [1 => 'Default Option Set', 2 => 'Zeta']], $this->store->choices());
        // The defaults form a valid new network once a name and range are given.
        $change = new DiscoveryNetworkChange(['name' => 'New', 'subnet_range' => '10.0.0.1'] + $defaults);
        self::assertSame(1, $change->totalIps);
    }

    public function testChangesRefuseValuesTheConsumersCannotUse(): void
    {
        $cases = [
            'subnet_range' => [['subnet_range' => '10.0.0.0/24,'], ['subnet_range' => '10.0.0.1-10.0.0.9'], ['subnet_range' => "10.0.0.0/24\n10.1.0.0/24"],
                ['subnet_range' => '0.0.0.0/1,128.0.0.0/1,10.0.0.0/29'], ['subnet_range' => str_repeat('10.0.0.1,', 114) . '10.0.0.1']],
            'name' => [['name' => ' '], ['name' => "Core\r\nBcc: x"], ['name' => str_repeat('n', 129)]],
            'notification_email' => [['notification_email' => "noc@example.net\r\nBcc: x@example.net"]],
            'notification_fromemail' => [['notification_fromemail' => 'not an address']],
            'dns_servers' => [['dns_servers' => '192.0.2.1;id'], ['dns_servers' => '192.0.2.1  192.0.2.2']],
            'start_at' => [['start_at' => '2026-02-30 10:00'], ['start_at' => 'tomorrow'], ['start_at' => '2026-11-09 24:00']],
            'threads' => [['threads' => 11]],
            'ping_method' => [['ping_method' => 0]],
            'ping_port' => [['ping_port' => 65536], ['ping_port' => null]],
            'monthly_week' => [['monthly_week' => [4]]],
            'day_of_week' => [['sched_type' => 3]],
            'day_of_month' => [['sched_type' => 4, 'month' => [1]]],
            'monthly_day' => [['sched_type' => 5, 'month' => [1], 'monthly_week' => [1]]],
        ];
        foreach ($cases as $field => $changes) {
            foreach ($changes as $change) {
                try {
                    new DiscoveryNetworkChange($change + self::FIELDS);
                    self::fail('Accepted ' . json_encode($change, JSON_THROW_ON_ERROR));
                } catch (DiscoveryNetworkRejected $error) {
                    self::assertSame($field, $error->field, json_encode($change, JSON_THROW_ON_ERROR));
                }
            }
        }
        // A disabled network may keep an incomplete schedule, as legacy stored it.
        self::assertSame([], (new DiscoveryNetworkChange(['sched_type' => 3, 'enabled' => false] + self::FIELDS))->settings['day_of_week']);
        $this->expectExceptionMessage('incomplete');
        new DiscoveryNetworkChange(['extra' => 1] + self::FIELDS);
    }
}
