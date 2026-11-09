<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\AssetManagement;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\AssetManagement\Application\AssetRegistry;
use Kadupul\AssetManagement\Application\Port\AssetAccess;
use Kadupul\AssetManagement\Application\ViewAssets;
use Kadupul\AssetManagement\Domain\AssetError;
use Kadupul\AssetManagement\Domain\AssetFields;
use Kadupul\AssetManagement\Infrastructure\Persistence\DoctrineAssetStore;
use Kadupul\AssetManagement\Infrastructure\Persistence\MonitoringSnapshot;
use Kadupul\IdentityAccess\Contract\Actor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AssetRegistryTest extends TestCase
{
    protected Connection $db;
    protected DoctrineAssetStore $store;
    protected AssetRegistry $registry;
    protected AssetAccess $access;
    protected bool $allowed = true;
    protected int $queries = 0;

    protected function queryConfiguration(): \Doctrine\DBAL\Configuration
    {
        $logger = new class (fn() => ++$this->queries) extends \Psr\Log\AbstractLogger {
            public function __construct(private readonly \Closure $count) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (isset($context['sql'])) {
                    ($this->count)();
                }
            }
        };
        $configuration = new \Doctrine\DBAL\Configuration();
        $configuration->setMiddlewares([new \Doctrine\DBAL\Logging\Middleware($logger)]);
        return $configuration;
    }

    protected function connection(): Connection
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $this->queryConfiguration());
        $db->executeStatement('CREATE TABLE asset_registry (id INTEGER PRIMARY KEY, revision INTEGER NOT NULL, policy TEXT NOT NULL, sources TEXT NOT NULL)');
        $db->executeStatement('CREATE TABLE asset_record (kind TEXT NOT NULL, record_id TEXT NOT NULL, data TEXT NOT NULL, PRIMARY KEY(kind, record_id))');
        $db->executeStatement('CREATE TABLE asset_event (sequence INTEGER PRIMARY KEY AUTOINCREMENT, request_id TEXT UNIQUE NOT NULL, data TEXT NOT NULL)');
        $db->executeStatement('CREATE TABLE asset_observation (sequence INTEGER PRIMARY KEY AUTOINCREMENT, run_id TEXT NOT NULL, identity_id TEXT NOT NULL, data TEXT NOT NULL, UNIQUE(run_id, identity_id))');
        $db->executeStatement("INSERT INTO asset_registry VALUES(1,0,'{}','{}')");
        return $db;
    }

    protected function setUp(): void
    {
        $this->db = $this->connection();
        $this->db->executeStatement('CREATE TABLE host (id INTEGER PRIMARY KEY, description VARCHAR(255), hostname VARCHAR(255), location VARCHAR(255), deleted VARCHAR(2))');
        $this->store = new DoctrineAssetStore($this->db);
        $this->access = $this->createMock(AssetAccess::class);
        $this->access->method('requireActor')->willReturnCallback(function (): Actor {
            if (!$this->allowed) {
                throw new AssetError('denied');
            }
            return new Actor(1, 'operator');
        });
        $this->registry = new AssetRegistry($this->access, $this->store, new MonitoringSnapshot($this->db), $this->createMock(\Kadupul\IdentityAccess\Contract\AuditTrail::class));
        $this->apply('configure', ['scope' => 'Production network equipment', 'steward' => 'Network operations', 'freshness_seconds' => 86400]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    protected function apply(string $action, array $data = []): array
    {
        return $this->registry->apply(['action' => $action, 'revision' => $this->store->snapshot()['revision'], 'request_id' => bin2hex(random_bytes(16)), ...$data]);
    }

    protected function asset(string $name = 'Core switch'): string
    {
        return $this->apply('create', ['fields' => ['name' => $name, 'owner' => 'Network operations', 'custodian' => 'Site team', 'service' => 'Production network']])['asset_id'];
    }

    protected function observation(string $key = 'serial-1', string $time = '2026-01-01T00:00:00Z'): array
    {
        return ['key' => $key, 'observed_at' => $time, 'fields' => ['name' => 'Observed switch', 'serial' => 'S1', 'manufacturer' => 'Example']];
    }

    protected function rejected(string $reason, \Closure $operation): void
    {
        $state = $this->store->snapshot();
        $events = $this->store->history(PHP_INT_MAX, 50);
        try {
            $operation();
            $this->fail('Expected refusal: ' . $reason);
        } catch (AssetError $error) {
            $this->assertSame($reason, $error->reason);
        }
        $this->assertSame($state, $this->store->snapshot());
        $this->assertSame($events, $this->store->history(PHP_INT_MAX, 50));
    }

    public function testImportLinkAcceptRetireAndHistory(): void
    {
        $id = $this->asset();
        $this->apply('import', ['source' => 'inventory/account-a', 'observations' => [$this->observation()]]);
        $identity = array_key_first($this->store->snapshot()['identities']);
        $this->apply('link', ['identity_id' => $identity, 'asset_id' => $id, 'evidence' => 'Physical serial check']);
        $view = (new ViewAssets($this->access, $this->store))();
        $this->assertSame(3, $view['metrics']['conflicts']);
        $this->assertSame('Core switch', $view['assets'][$id]['fields']['name']);
        $this->apply('accept', ['identity_id' => $identity, 'fields' => ['serial', 'manufacturer'], 'evidence' => 'Verified nameplate']);
        foreach (['received', 'active', 'retired', 'disposed'] as $state) {
            $this->apply('transition', ['asset_id' => $id, 'state' => $state, 'evidence' => 'Change ticket and disposal certificate']);
        }
        $this->assertSame('disposed', $this->store->snapshot()['assets'][$id]['state']);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM asset_observation'));
        $this->assertCount(9, $this->store->history(PHP_INT_MAX, 50));
    }

    public function testIdempotentReceiptAndCollision(): void
    {
        $command = ['action' => 'create', 'request_id' => bin2hex(random_bytes(16)), 'revision' => 1, 'fields' => ['name' => 'Switch']];
        $result = $this->registry->apply($command);
        $this->assertSame($result, $this->registry->apply($command));
        $this->assertCount(1, $this->store->snapshot()['assets']);
        $this->rejected('request_reused', fn() => $this->registry->apply(array_replace($command, ['fields' => ['name' => 'Other']])));
    }

    public function testMaximumImportAndAssetCapacityKeepTheReadQueryBudgetFixed(): void
    {
        $rows = [];
        for ($i = 0; $i < 100; $i++) {
            $rows[] = $this->observation('equipment-' . $i);
        }
        $this->assertSame(100, $this->apply('import', ['source' => 'capacity', 'observations' => $rows])['observations']);
        $rows[] = $this->observation('overflow');
        $this->rejected('invalid_observation', fn() => $this->apply('import', ['source' => 'capacity', 'observations' => $rows]));
        $id = $this->asset();
        $asset = $this->store->snapshot()['assets'][$id];
        // Representative persisted rows use the actual successful producer's
        // record shape; exercise the real reader at the supported population.
        for ($i = 1; $i < 500; $i++) {
            $asset['id'] = str_pad(dechex($i), 32, '0', STR_PAD_LEFT);
            $this->db->insert('asset_record', ['kind' => 'assets', 'record_id' => $asset['id'], 'data' => json_encode($asset, JSON_THROW_ON_ERROR)]);
        }
        $this->queries = 0;
        $view = (new ViewAssets($this->access, $this->store))();
        $this->assertSame(4, $this->queries, 'Snapshot and history must not query once per asset or identity.');
        $this->assertSame(500, $view['metrics']['registered']);
        $this->assertSame(100, $view['metrics']['unlinked']);
        $this->rejected('capacity', fn() => $this->asset('Beyond pilot capacity'));
    }

    public function testStaleRevisionCannotOverwriteConcurrentEdit(): void
    {
        $id = $this->asset();
        $revision = $this->store->snapshot()['revision'];
        $this->apply('edit', ['asset_id' => $id, 'fields' => ['owner' => 'New team'], 'evidence' => 'Accepted handover']);
        $this->rejected('stale_revision', fn() => $this->registry->apply(['action' => 'edit', 'request_id' => bin2hex(random_bytes(16)), 'revision' => $revision, 'asset_id' => $id, 'fields' => ['name' => 'Lost update'], 'evidence' => 'Stale form']));
        $this->assertSame('Core switch', $this->store->snapshot()['assets'][$id]['fields']['name']);
    }

    public function testExternalKeysCannotSilentlyCollapseThroughWhitespaceNormalization(): void
    {
        $this->apply('import', ['source' => 'inventory', 'observations' => [$this->observation('device-1')]]);
        $this->rejected('invalid_observation', fn() => $this->apply('import', ['source' => 'inventory', 'observations' => [$this->observation('device-1 ', '2026-01-02T00:00:00Z')]]));
        $this->rejected('invalid_observation', fn() => $this->apply('import', ['source' => ' inventory', 'observations' => [$this->observation('device-1', '2026-01-02T00:00:00Z')]]));
        $this->assertCount(1, $this->store->snapshot()['identities']);
        $this->assertSame('2026-01-01T00:00:00Z', array_values($this->store->snapshot()['identities'])[0]['observed_at']);
    }

    public function testFailedAndEmptyCollectionsPreserveAssetsAndObservations(): void
    {
        $id = $this->asset();
        $this->apply('import', ['source' => 'inventory', 'observations' => [$this->observation('a', gmdate('Y-m-d\TH:i:s\Z'))]]);
        $identity = array_key_first($this->store->snapshot()['identities']);
        $this->apply('link', ['identity_id' => $identity, 'asset_id' => $id, 'evidence' => 'Verified']);
        foreach (['received', 'active'] as $state) {
            $this->apply('transition', ['asset_id' => $id, 'state' => $state, 'evidence' => 'Commissioning']);
        }
        $this->assertSame(1, (new ViewAssets($this->access, $this->store))()['metrics']['fresh_active']);
        $this->apply('source_failure', ['source' => 'inventory']);
        $this->assertSame(0, (new ViewAssets($this->access, $this->store))()['metrics']['fresh_active']);
        $this->apply('import', ['source' => 'inventory', 'observations' => []]);
        $this->assertSame('active', $this->store->snapshot()['assets'][$id]['state']);
        $this->assertCount(1, $this->store->snapshot()['identities']);
    }

    public function testSourceScopesDoNotMergeAndMappingCanBeCorrected(): void
    {
        $first = $this->asset();
        $second = $this->asset('Other');
        foreach (['account-a', 'account-b'] as $source) {
            $this->apply('import', ['source' => $source, 'observations' => [$this->observation()]]);
        }
        $this->assertCount(2, $this->store->snapshot()['identities']);
        $identity = array_key_first($this->store->snapshot()['identities']);
        foreach ([$first, $second, $first, ''] as $id) {
            $this->apply('link', ['identity_id' => $identity, 'asset_id' => $id, 'evidence' => 'Reviewed physical identity']);
            $this->assertSame($id, $this->store->snapshot()['identities'][$identity]['asset_id']);
        }
        $this->assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM asset_observation'));
    }

    public function testDependencyChecksAndCycleRefusal(): void
    {
        $a = $this->asset('A');
        $b = $this->asset('B');
        $c = $this->asset('C');
        foreach ([[$a, $b], [$b, $c]] as [$from, $to]) {
            $this->apply('dependency', ['asset_id' => $from, 'depends_on' => $to, 'remove' => false, 'evidence' => 'Topology review']);
        }
        $this->rejected('dependency_cycle', fn() => $this->apply('dependency', ['asset_id' => $c, 'depends_on' => $a, 'remove' => false, 'evidence' => 'Cycle']));
        $this->apply('transition', ['asset_id' => $b, 'state' => 'received', 'evidence' => 'Received']);
        $this->rejected('dependent_assets', fn() => $this->apply('transition', ['asset_id' => $b, 'state' => 'retired', 'evidence' => 'Retirement']));
        $this->apply('dependency', ['asset_id' => $a, 'depends_on' => $b, 'remove' => true, 'evidence' => 'Service moved']);
        $this->apply('transition', ['asset_id' => $b, 'state' => 'retired', 'evidence' => 'Retirement']);
        $this->assertSame('retired', $this->store->snapshot()['assets'][$b]['state']);
    }

    public function testOwnershipAndDispositionGuards(): void
    {
        $id = $this->apply('create', ['fields' => ['name' => 'Unowned']])['asset_id'];
        $this->apply('transition', ['asset_id' => $id, 'state' => 'received', 'evidence' => 'Receipt']);
        $this->rejected('ownership_required', fn() => $this->apply('transition', ['asset_id' => $id, 'state' => 'active', 'evidence' => 'Missing owner']));
        $this->rejected('invalid_transition', fn() => $this->apply('transition', ['asset_id' => $id, 'state' => 'disposed', 'evidence' => 'Too early']));
    }

    public function testInvalidLaterImportRollsBackWholeBatch(): void
    {
        $this->rejected('invalid_fields', fn() => $this->apply('import', ['source' => 'inventory', 'observations' => [$this->observation(), ['key' => 'broken', 'observed_at' => '2026-01-01T00:00:00Z', 'fields' => ['serial' => ['nested']]]]]));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM asset_observation'));
    }

    public function testOutOfOrderAndAmbiguousObservationTimesAreRefused(): void
    {
        $this->apply('import', ['source' => 'inventory', 'observations' => [$this->observation()]]);
        $this->rejected('older_observation', fn() => $this->apply('import', ['source' => 'inventory', 'observations' => [$this->observation('serial-1', '2025-01-01T00:00:00Z')]]));
        $changed = $this->observation();
        $changed['fields']['name'] = 'Different';
        $this->rejected('older_observation', fn() => $this->apply('import', ['source' => 'inventory', 'observations' => [$changed]]));
        $this->apply('import', ['source' => 'inventory', 'observations' => [$this->observation()]]);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM asset_observation'));
    }

    public function testMonitoringImportUsesOnlySelectedExistingDevicesAndDoesNotWriteThem(): void
    {
        $this->db->insert('host', ['id' => 1, 'description' => 'Switch', 'hostname' => '192.0.2.1', 'location' => 'Rack A', 'deleted' => '']);
        $this->apply('monitoring', ['device_ids' => [1]]);
        $identity = array_values($this->store->snapshot()['identities'])[0];
        $this->assertSame('kadupul-monitoring', $identity['source']);
        $this->assertSame('192.0.2.1', $identity['fields']['address']);
        $this->assertSame('', $identity['asset_id']);
        $this->rejected('not_found', fn() => $this->apply('monitoring', ['device_ids' => [1, 2]]));
        $this->assertSame('Switch', $this->db->fetchOne('SELECT description FROM host WHERE id=1'));
    }

    public function testBaselineAndReviewMetricsKeepUnknownSeparate(): void
    {
        $id = $this->asset();
        $view = new ViewAssets($this->access, $this->store);
        $this->assertSame(0, $view()['metrics']['baseline_total']);
        $this->apply('baseline', ['asset_ids' => [$id, str_repeat('f', 32)], 'evidence' => 'Physical audit includes a missing record']);
        $this->apply('review', ['kind' => 'identity_sample', 'sample_size' => 10, 'incorrect_merges' => 1, 'missed_duplicates' => 2, 'evidence' => 'Reviewed independent sample']);
        $this->assertSame(2, $view()['metrics']['baseline_total']);
        $this->assertSame(1, $view()['metrics']['baseline_present']);
        $this->assertSame(10, $view()['policy']['reviews']['identity_sample']['sample_size']);
    }

    public function testAuthorizationPrecedesReadsAndMutations(): void
    {
        $this->allowed = false;
        $this->rejected('denied', fn() => $this->registry->apply([]));
        $this->expectException(AssetError::class);
        (new ViewAssets($this->access, $this->store))();
    }

    public function testCallerTransactionIsPreserved(): void
    {
        $this->db->beginTransaction();
        try {
            $this->rejected('caller_transaction', fn() => $this->asset());
            $this->assertTrue($this->db->isTransactionActive());
        } finally {
            $this->db->rollBack();
        }
    }

    public function testJournalFailureRollsBackAcceptedChanges(): void
    {
        $this->db->executeStatement('DROP TABLE asset_event');
        $before = $this->store->snapshot();
        try {
            $this->asset();
            $this->fail('Missing journal must fail');
        } catch (\Doctrine\DBAL\Exception) {
            $this->assertSame($before, $this->store->snapshot());
        }
    }

    public static function malformed(): iterable
    {
        yield [['name' => '']];
        yield [['name' => ['nested']]];
        yield [['name' => str_repeat('東', 501)]];
        yield [['name' => 'X', 'purchase_cost_minor' => '1e3', 'currency' => 'USD']];
        yield [['name' => 'X', 'support_until' => '2026-02-30']];
        yield [['name' => 'X', 'credential' => 'forbidden']];
        yield [['name' => "X\0Y"]];
    }

    #[DataProvider('malformed')]
    public function testMalformedFieldsCannotBePersisted(array $fields): void
    {
        $this->rejected('invalid_fields', fn() => $this->apply('create', ['fields' => $fields]));
    }
}
