<?php

declare(strict_types=1);

/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Application\Port\Clock;
use Kadupul\Platform\Infrastructure\Legacy\LegacyConnectionRegistry;
use Kadupul\Platform\Infrastructure\Legacy\LocalRrdVerification;
use Kadupul\Platform\Infrastructure\Legacy\NativeOperationalEvidence;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class InstallerOperationalNativeEvidenceTest extends TestCase
{
    private OperationalEvidencePdo $database;
    private string $schema;
    private string $directory;
    private string $binary;
    private int $now;
    private bool $created = false;

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')) {
            $this->markTestSkipped('A disposable MySQL/MariaDB fixture is required.');
        }
        $this->binary = getenv('KADUPUL_TEST_RRDTOOL_BINARY') ?: (is_file('/opt/homebrew/bin/rrdtool') ? '/opt/homebrew/bin/rrdtool' : '/usr/bin/rrdtool');
        if (!is_executable($this->binary)) {
            $this->markTestSkipped('An existing real RRDtool binary is required.');
        }
        $this->database = new OperationalEvidencePdo($dsn, getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->schema = 'operational_evidence_' . bin2hex(random_bytes(8));
        $this->database->exec('CREATE DATABASE ' . $this->schema);
        $this->created = true;
        $this->database->exec('USE ' . $this->schema);
        $this->database->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION,ALLOW_INVALID_DATES'");
        $this->database->exec("SET SESSION time_zone = '+00:00'");
        $source = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        $this->assertIsString($source);
        foreach (['settings', 'poller', 'graph_templates_item', 'data_template_rrd', 'data_template_data'] as $table) {
            $this->assertSame(1, preg_match('/CREATE TABLE `?' . $table . '`? \(.*?;\n/s', $source, $match));
            $this->database->exec($match[0]);
        }
        $this->directory = sys_get_temp_dir() . '/operational-rrd-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->now = time();
        (new Process([$this->binary, 'create', $this->directory . '/value.rrd', '--start', (string) ($this->now - 300), '--step', '60', 'DS:value:GAUGE:120:U:U', 'RRA:AVERAGE:0.5:1:10']))->mustRun();
        (new Process([$this->binary, 'update', $this->directory . '/value.rrd', $this->now . ':1']))->mustRun();
        $this->database->exec("INSERT INTO poller (id,status,total_polls,last_update) VALUES (1,2,1,FROM_UNIXTIME($this->now))");
        $this->database->exec('INSERT INTO data_template_rrd (id,local_data_id,rrd_heartbeat) VALUES (1,1,120)');
        $this->database->exec("INSERT INTO data_template_data (local_data_id,data_source_path) VALUES (1,'<path_rra>/value.rrd')");
        $this->store('cron_interval', '300');
        $this->store('poller_interval', '300');
        $this->store('poller_lastrun_1', (string) $this->now);
        $this->store('path_rrdtool', $this->binary);
        require_once dirname(__DIR__, 2) . '/include/global_constants.php';
        require_once dirname(__DIR__, 2) . '/lib/rrd.php';
        // Object authorization is an explicit boundary here. Use-case tests
        // exercise its gates; these cases execute actual schema and RRDtool I/O.
        eval('function is_graph_allowed($id, $actor) { return $actor === 7 && in_array($id, $GLOBALS["operational_allowed_graphs"], true); }');
        $GLOBALS['operational_allowed_graphs'] = range(1, 20);
        $GLOBALS['config'] = ['poller_id' => 1, 'rra_path' => $this->directory];
        $GLOBALS['database_hostname'] = 'operational-fixture';
        $GLOBALS['database_port'] = 0;
        $GLOBALS['database_default'] = $this->schema;
        $GLOBALS['local_db_cnn_id'] = null;
        LegacyConnectionRegistry::setDefaultSession($this->database);
        $this->database->prepares = 0;
    }

    protected function tearDown(): void
    {
        if ($this->created) {
            $this->database->exec('DROP DATABASE ' . $this->schema);
        }
        if (isset($this->directory)) {
            foreach (glob($this->directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
    }

    private function store(string $name, string $value): void
    {
        $this->database->prepare('REPLACE INTO settings (name,value) VALUES (?,?)')->execute([$name, $value]);
    }

    private function evidence(): NativeOperationalEvidence
    {
        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('@' . $this->now));
        return new NativeOperationalEvidence($clock, new LocalRrdVerification());
    }

    public function testActualSchemaAndRealRrdEvidenceAreReadOnlyAndQueryBounded(): void
    {
        foreach (range(1, 20) as $id) {
            $this->database->exec("INSERT INTO graph_templates_item (local_graph_id,task_item_id) VALUES ($id,1)");
        }
        $before = $this->database->query('SELECT * FROM data_template_data')->fetchAll();
        $hash = hash_file('sha256', $this->directory . '/value.rrd');
        putenv('RRDCACHED_ADDRESS=tcp:127.0.0.1:9');
        try {
            $report = $this->evidence()->inspect(7, range(1, 20));
        } finally {
            putenv('RRDCACHED_ADDRESS');
        }
        $this->assertSame(23, $this->database->prepares, 'Two fixed reads, twenty graph reads and one shared data-source lookup.');
        $this->assertSame('verified', $report['scheduler']['status']);
        $this->assertSame('observed', $report['collection']['status']);
        $this->assertSame($this->now, $report['graphs'][0]['sources'][0]['last_update']);
        $this->assertSame('observed', $report['graphs'][0]['sources'][0]['status']);
        $this->assertSame('not_assessed', $report['graphs'][0]['rendering']);
        $this->assertFalse($report['operational_health_confirmed']);
        $this->assertSame($hash, hash_file('sha256', $this->directory . '/value.rrd'));
        $this->assertSame($before, $this->database->query('SELECT * FROM data_template_data')->fetchAll());
        $this->assertStringNotContainsString($this->directory, json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function testFreshAbsenceStaleAndRemoteStorageRemainDistinct(): void
    {
        $this->database->exec('INSERT INTO graph_templates_item (local_graph_id,task_item_id) VALUES (1,1)');
        $this->store('poller_lastrun_1', '0');
        $this->database->exec('UPDATE poller SET total_polls=0');
        $this->database->exec("UPDATE data_template_data SET data_source_path=''");
        $fresh = $this->evidence()->inspect(7, [1]);
        $this->assertSame('not_assessed', $fresh['scheduler']['status']);
        $this->assertSame('absent', $fresh['collection']['status']);
        $this->assertSame('path_not_persisted', $fresh['graphs'][0]['sources'][0]['reason']);
        $this->assertSame('', $this->database->query('SELECT data_source_path FROM data_template_data')->fetchColumn());
        $this->store('poller_lastrun_1', (string) ($this->now - 1000));
        $this->database->exec('UPDATE poller SET total_polls=1,last_update=FROM_UNIXTIME(' . ($this->now - 1000) . ')');
        $this->assertSame('stale', $this->evidence()->inspect(7, [])['collection']['status']);
        $this->store('storage_location', '1');
        $this->assertSame('remote_storage_not_contacted', $this->evidence()->inspect(7, [1])['graphs'][0]['sources'][0]['reason']);
    }

    public function testGraphDenialPrecedesProtectedMetadataReads(): void
    {
        $this->expectException(\RuntimeException::class);
        try {
            $this->evidence()->inspect(8, [1]);
        } finally {
            $this->assertSame(0, $this->database->prepares);
        }
    }

    public function testUnconfirmedLateDriverReadCannotBecomeEmptyOperationalEvidence(): void
    {
        $this->database->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [OperationalFaultStatement::class]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fetch could not be confirmed');
        $this->evidence()->inspect(7, []);
    }

    public function testUnknownTokensAndOutsideSymlinksAreRejectedBeforeExecutableUse(): void
    {
        $rrd = new LocalRrdVerification();
        $this->assertSame('local_path_rejected', $rrd->read('/not/an/executable', '<unknown>/value.rrd', $this->directory, 1.0)['reason']);
        symlink(dirname(__DIR__, 2) . '/composer.json', $this->directory . '/outside.rrd');
        $this->assertSame('local_path_rejected', $rrd->read('/not/an/executable', '<path_rra>/outside.rrd', $this->directory, 1.0)['reason']);
        $this->assertSame('absent', $rrd->read($this->binary, '<path_rra>/missing.rrd', $this->directory, 1.0)['status']);
        symlink($this->directory . '/value.rrd', $this->directory . '/inside.rrd');
        $this->assertSame('observed', $rrd->read($this->binary, '<path_rra>/inside.rrd', $this->directory, 1.0)['status']);
        $alias = $this->directory . '-root-alias';
        symlink($this->directory, $alias);
        try {
            $this->assertSame('observed', $rrd->read($this->binary, '<path_rra>/value.rrd', $alias, 1.0)['status']);
        } finally {
            unlink($alias);
        }
    }

    public function testRealRrdTimestampsBecomeStaleOrFutureWithoutClaimingRendering(): void
    {
        $this->database->exec('INSERT INTO graph_templates_item (local_graph_id,task_item_id) VALUES (1,1)');
        $this->now += 121;
        $report = $this->evidence()->inspect(7, [1]);
        $this->assertSame('stale', $report['graphs'][0]['sources'][0]['status']);
        $this->assertSame('not_assessed', $report['graphs'][0]['rendering']);
        $this->now -= 122;
        $report = $this->evidence()->inspect(7, [1]);
        $this->assertSame('failed', $report['graphs'][0]['sources'][0]['status']);
        $this->assertSame('local_rrd_timestamp_in_future', $report['graphs'][0]['sources'][0]['reason']);
    }

    public function testConcurrentPersistedUpdatesAreComparedWithTimeAfterTheirRead(): void
    {
        $this->database->exec('INSERT INTO graph_templates_item (local_graph_id,task_item_id) VALUES (1,1)');
        $updated = $this->now + 1;
        $this->database->beforePrepare = function () use ($updated): void {
            $this->database->beforePrepare = null;
            $this->store('poller_lastrun_1', (string) $updated);
            $this->database->exec('UPDATE poller SET last_update=FROM_UNIXTIME(' . $updated . ')');
            (new Process([$this->binary, 'update', $this->directory . '/value.rrd', $updated . ':2']))->mustRun();
        };
        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturnOnConsecutiveCalls(
            new \DateTimeImmutable('@' . $this->now),
            new \DateTimeImmutable('@' . $updated),
            new \DateTimeImmutable('@' . $updated),
            new \DateTimeImmutable('@' . $updated)
        );
        $report = (new NativeOperationalEvidence($clock, new LocalRrdVerification()))->inspect(7, [1]);
        $this->assertSame($this->now, $report['sampled_at']);
        $this->assertSame('verified', $report['scheduler']['status']);
        $this->assertSame('observed', $report['collection']['status']);
        $this->assertSame('observed', $report['graphs'][0]['sources'][0]['status']);
        $this->assertSame($updated, $report['graphs'][0]['sources'][0]['last_update']);
    }

    public function testOversizedGraphSourceSelectionIsReportedWithoutPartialSuccess(): void
    {
        foreach (range(1, 21) as $id) {
            if ($id > 1) {
                $this->database->exec("INSERT INTO data_template_rrd (id,local_data_id) VALUES ($id,$id)");
            }
            $this->database->exec("INSERT INTO graph_templates_item (local_graph_id,task_item_id) VALUES (1,$id)");
        }
        $report = $this->evidence()->inspect(7, [1]);
        $this->assertSame('not_assessed', $report['graphs'][0]['metadata']);
        $this->assertSame('graph_source_limit_exceeded', $report['graphs'][0]['reason']);
        $this->assertSame([], $report['graphs'][0]['sources']);
        $this->assertSame(3, $this->database->prepares);
    }
}

final class OperationalEvidencePdo extends \PDO
{
    public int $prepares = 0;
    public ?\Closure $beforePrepare = null;

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        if ($this->beforePrepare !== null) {
            ($this->beforePrepare)();
        }
        $this->prepares++;
        return parent::prepare($query, $options);
    }
}

final class OperationalFaultStatement extends \PDOStatement
{
    protected function __construct() {}

    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        parent::fetchAll($mode, ...$args);
        return [];
    }

    public function errorCode(): ?string
    {
        return 'HY000';
    }
}
