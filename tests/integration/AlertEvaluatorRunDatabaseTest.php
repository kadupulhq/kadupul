<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * kadupul:alerting:evaluate with its DBAL settings, named locks and stores on
 * MariaDB or MySQL, over the shipped cacti.sql tables in a schema this test
 * owns and drops. Each run uses its own session, as two processes would.
 * Delivery is a recording drain: the real one starts the legacy worker,
 * which needs a configured installation.
 */

namespace AlertEvaluatorRunDatabaseTest;

$applicationLoader = require dirname(__DIR__, 2) . '/include/vendor/autoload.php';
$applicationLoader->unregister();
$applicationLoader->register(false);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Alerting\Application\EvaluateRules;
use Kadupul\Alerting\Application\Port\RateSource;
use Kadupul\Alerting\Application\Port\RunLock;
use Kadupul\Alerting\Application\RunAlerting;
use Kadupul\Alerting\Infrastructure\Dbal\DbalAlertingSettings;
use Kadupul\Alerting\Infrastructure\Dbal\DbalEvaluationCursor;
use Kadupul\Alerting\Infrastructure\Dbal\DbalIncidentRepository;
use Kadupul\Alerting\Infrastructure\Dbal\DbalNotificationQueue;
use Kadupul\Alerting\Infrastructure\Dbal\DbalRuleRepository;
use Kadupul\Alerting\Infrastructure\Dbal\DbalRuleStateStore;
use Kadupul\Alerting\Infrastructure\Dbal\DbalRunLock;
use Kadupul\Alerting\Infrastructure\Dbal\DbalTransactionRunner;
use Kadupul\Alerting\Infrastructure\Dbal\DsstatsBaselineSource;
use Kadupul\Alerting\Infrastructure\Dbal\DsstatsRateSource;
use Kadupul\Alerting\Infrastructure\Symfony\EvaluateCommand;
use Kadupul\Tests\Alerting\Fakes\MockClockAdapter;
use Kadupul\Tests\Alerting\Fakes\RecordingLogger;
use Kadupul\Tests\Alerting\Fakes\RecordingNotificationDrain;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

const TABLES = ['alert_rule', 'alert_rule_state', 'alert_incident', 'alert_notification', 'alert_baseline_sample', 'settings', 'host', 'data_local', 'data_template_data', 'data_template_rrd', 'data_source_stats_hourly_cache', 'data_source_stats_hourly_last'];

/** Runs $during once, inside the first rate read: a second process starting while this one evaluates. */
final class InterleavedRates implements RateSource
{
    private bool $done = false;

    public function __construct(private readonly RateSource $inner, private readonly \Closure $during) {}

    public function available(): bool
    {
        return $this->inner->available();
    }

    public function currentRates(array $keys): array
    {
        if (!$this->done) {
            $this->done = true;
            ($this->during)();
        }

        return $this->inner->currentRates($keys);
    }
}

final class FailingRates implements RateSource
{
    public function __construct(private readonly RateSource $inner) {}

    public function available(): bool
    {
        return $this->inner->available();
    }

    public function currentRates(array $keys): array
    {
        throw new \RuntimeException('injected rate read failure');
    }
}

final class AlertEvaluatorRunDatabaseTest extends TestCase
{
    private const int NOW = 1_800_000_000;
    private const int STEP = 300;

    private Connection $database;
    /** @var list<Connection> */
    private array $sessions = [];
    private string $schema = '';
    private MockClock $clock;
    private RecordingNotificationDrain $drain;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('Set KADUPUL_TEST_MYSQL_DSN to a task-owned MariaDB or MySQL server; these alert evaluator run tests were not run.');
        }
        $this->schema = 'alert_run_' . bin2hex(random_bytes(6));
        $this->database = $this->connect();
        $this->database->executeStatement('CREATE DATABASE `' . $this->schema . '`');
        $this->use($this->database, $this->schema);

        $sql = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        self::assertIsString($sql);
        foreach (TABLES as $table) {
            self::assertSame(1, preg_match('/^CREATE TABLE `?' . $table . '`? \(.*?^\s*\) [^;\n]*;/ms', $sql, $create), $table);
            $this->database->executeStatement(rtrim($create[0], ';'));
        }
        $this->database->insert('settings', ['name' => 'dsstats_enable', 'value' => 'on']);
        $this->database->insert('settings', ['name' => 'alerting_enable', 'value' => 'on']);

        $this->clock = new MockClock(new \DateTimeImmutable('@' . self::NOW));
        $this->drain = new RecordingNotificationDrain();
        $this->logger = new RecordingLogger();
        $this->watch(1, 101);
        $this->sample(101, self::NOW, 95.0);
    }

    protected function tearDown(): void
    {
        foreach ($this->sessions as $session) {
            $session->close();
        }
        if (isset($this->database)) {
            if ($this->schema !== '') {
                $this->database->executeStatement('DROP DATABASE IF EXISTS `' . $this->schema . '`');
            }
            $this->database->close();
        }
    }

    private function connect(): Connection
    {
        parse_str(str_replace(';', '&', substr((string) getenv('KADUPUL_TEST_MYSQL_DSN'), strlen('mysql:'))), $target);
        $prefix = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ? 'KADUPUL_TEST_MYSQL_ADMIN_' : 'KADUPUL_TEST_MYSQL_';

        return DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => is_string($target['host'] ?? null) ? $target['host'] : '127.0.0.1',
            'port' => (int) ($target['port'] ?? 3306),
            'user' => (string) (getenv($prefix . 'USER') ?: 'root'),
            'password' => (string) (getenv($prefix . 'PASSWORD') ?: ''),
            'charset' => 'utf8mb4',
        ]);
    }

    private function use(Connection $connection, string $schema): void
    {
        $connection->executeStatement('USE `' . $schema . '`');
        // The legacy session modes (lib/database.php) so the shipped zero-date DDL loads on MySQL too.
        $connection->executeStatement("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION,ALLOW_INVALID_DATES'");
        $connection->executeStatement("SET time_zone = '+00:00'");
    }

    /** A new session on this test's schema, as another evaluator process would open. */
    private function session(?string $schema = null): Connection
    {
        $session = $this->connect();
        $this->use($session, $schema ?? $this->schema);
        $this->sessions[] = $session;

        return $session;
    }

    /** The command wired as services.yaml wires it, on one session. */
    private function command(Connection $session, ?\Closure $rates = null): CommandTester
    {
        $source = new DsstatsRateSource($session);
        $clock = new MockClockAdapter($this->clock);
        $evaluate = new EvaluateRules(
            $rates === null ? $source : $rates($source),
            new DsstatsBaselineSource($session),
            new DbalRuleRepository($session),
            new DbalRuleStateStore($session),
            new DbalIncidentRepository($session),
            new DbalNotificationQueue($session),
            new DbalEvaluationCursor($session),
            $clock,
            new DbalTransactionRunner($session),
            $this->logger,
        );

        return new CommandTester(new EvaluateCommand(new RunAlerting(new DbalAlertingSettings($session), new DbalRunLock($session), $evaluate, $this->drain, $clock, $this->logger), $this->logger));
    }

    private function watch(int $ruleId, int $localDataId): void
    {
        $this->database->insert('host', ['id' => 10, 'description' => 'device 10', 'hostname' => '192.0.2.10']);
        $this->database->insert('data_local', ['id' => $localDataId, 'host_id' => 10]);
        $this->database->insert('data_template_data', ['local_data_id' => $localDataId, 'rrd_step' => self::STEP, 'name' => 'ds ' . $localDataId]);
        $this->database->insert('data_template_rrd', ['local_data_id' => $localDataId, 'data_source_name' => 'traffic_in']);
        $this->database->insert('alert_rule', ['id' => $ruleId, 'local_data_id' => $localDataId, 'rrd_name' => 'traffic_in', 'comparator' => 'gt', 'trigger_threshold' => 90, 'clear_threshold' => 80, 'for_cycles' => 1]);
    }

    private function sample(int $localDataId, int $time, float $rate): void
    {
        $this->database->executeStatement(
            'INSERT INTO data_source_stats_hourly_cache (local_data_id, rrd_name, time, value) VALUES (?, ?, FROM_UNIXTIME(?), ?)',
            [$localDataId, 'traffic_in', $time, $rate],
        );
        $this->database->executeStatement(
            'INSERT INTO data_source_stats_hourly_last (local_data_id, rrd_name, value, calculated) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE value = VALUES(value), calculated = VALUES(calculated)',
            [$localDataId, 'traffic_in', $rate, $rate],
        );
    }

    private function stateRows(): int
    {
        return (int) $this->database->fetchOne('SELECT COUNT(*) FROM alert_rule_state');
    }

    /** Whether no session holds the lock, read from a session that never took it. */
    private function free(string $purpose): bool
    {
        $probe = $this->session();
        $lock = new DbalRunLock($probe);
        $taken = $lock->acquire($purpose);
        if ($taken) {
            self::assertTrue($lock->release($purpose));
        }

        return $taken;
    }

    public function testExitsZeroAfterEvaluatingAndOpensTheIncident(): void
    {
        $tester = $this->command($this->session());

        self::assertSame(0, $tester->execute([]));
        self::assertStringStartsWith('Alert rules: 1 evaluated,', $tester->getDisplay());
        self::assertSame(1, $this->stateRows());
        self::assertSame(['open'], array_map('strval', $this->database->fetchFirstColumn('SELECT status FROM alert_incident')));
        self::assertSame(['opened'], array_map('strval', $this->database->fetchFirstColumn('SELECT kind FROM alert_notification')));
        self::assertCount(1, $this->drain->calls);
        self::assertTrue($this->free(RunLock::EVALUATE));
        self::assertTrue($this->free(RunLock::DELIVER));
    }

    public function testExitsZeroWithAnInactiveMessageWhenAlertingIsOff(): void
    {
        $this->database->executeStatement("DELETE FROM settings WHERE name = 'alerting_enable'");
        $tester = $this->command($this->session());

        self::assertSame(0, $tester->execute([]));
        self::assertSame("inactive: alerting is disabled (alerting_enable is off).\n", $tester->getDisplay());
        self::assertSame(0, $this->stateRows());
        self::assertSame([], $this->drain->calls);
    }

    public function testExitsTwoWhenDsstatsIsOff(): void
    {
        $this->database->executeStatement("UPDATE settings SET value = '' WHERE name = 'dsstats_enable'");
        $tester = $this->command($this->session());

        self::assertSame(2, $tester->execute([]));
        self::assertSame(0, $this->stateRows());
        self::assertTrue($this->free(RunLock::EVALUATE));
    }

    public function testASecondConcurrentRunExitsZeroWithoutEvaluating(): void
    {
        $second = null;
        $secondStatus = null;
        $first = $this->command($this->session(), function (RateSource $source) use (&$second, &$secondStatus): RateSource {
            return new InterleavedRates($source, function () use (&$second, &$secondStatus): void {
                // The first run holds the evaluation lock and has written nothing yet.
                $second = $this->command($this->session());
                $secondStatus = $second->execute([]);
            });
        });

        self::assertSame(0, $first->execute([]));

        self::assertSame(0, $secondStatus);
        self::assertSame("skipped: another alert evaluation is running.\n", $second?->getDisplay());
        self::assertStringStartsWith('Alert rules: 1 evaluated,', $first->getDisplay());
        self::assertSame(1, $this->stateRows(), 'Only the first run evaluated.');
        self::assertCount(1, $this->drain->calls, 'Only the first run delivered.');
        self::assertTrue($this->free(RunLock::EVALUATE));
    }

    public function testTheLockIsReleasedWhenEvaluationFails(): void
    {
        $session = $this->session();
        $tester = $this->command($session, static fn(RateSource $source): RateSource => new FailingRates($source));

        self::assertSame(1, $tester->execute([]));
        self::assertTrue($this->free(RunLock::EVALUATE), 'A failed run must not keep later runs out while its session lives on.');
        self::assertSame(1, (int) $session->fetchOne('SELECT 1'), 'The failed run kept its session.');
    }

    public function testTheLockEndsWithTheSessionThatHeldIt(): void
    {
        $holder = $this->session();
        self::assertTrue((new DbalRunLock($holder))->acquire(RunLock::EVALUATE));
        self::assertFalse($this->free(RunLock::EVALUATE));

        // A killed evaluator: its connection closes without releasing.
        $holder->close();

        self::assertTrue($this->free(RunLock::EVALUATE));
    }

    public function testTheTwoLocksAreIndependent(): void
    {
        $holder = $this->session();
        self::assertTrue((new DbalRunLock($holder))->acquire(RunLock::DELIVER));

        $tester = $this->command($this->session());
        self::assertSame(0, $tester->execute([]));

        self::assertSame(1, $this->stateRows(), 'A running drain does not stop evaluation.');
        self::assertStringEndsWith("Notifications: another delivery is running.\n", $tester->getDisplay());
        self::assertSame([], $this->drain->calls);
    }

    public function testAnotherInstallationOnTheSameServerHasItsOwnLock(): void
    {
        $other = 'alert_run_' . bin2hex(random_bytes(6));
        $this->database->executeStatement('CREATE DATABASE `' . $other . '`');
        try {
            $mine = new DbalRunLock($this->session());
            $theirs = new DbalRunLock($this->session($other));
            self::assertTrue($mine->acquire(RunLock::EVALUATE));
            self::assertTrue($theirs->acquire(RunLock::EVALUATE));
            self::assertTrue($mine->release(RunLock::EVALUATE));
            self::assertTrue($theirs->release(RunLock::EVALUATE));
            self::assertFalse($mine->release(RunLock::EVALUATE), 'A lock this session no longer holds is reported.');
        } finally {
            $this->database->executeStatement('DROP DATABASE IF EXISTS `' . $other . '`');
        }
    }

    public function testTheCursorIsNotReplicatedToCollectors(): void
    {
        // The settings query replicate_out() sends to each collector; the
        // poller replication sequence test pins it to lib/poller.php.
        $fixture = json_decode((string) file_get_contents(dirname(__DIR__) . '/Fixtures/poller-replication/replicate-out-sequence.json'), true, 16, JSON_THROW_ON_ERROR);
        $query = $fixture['sql'][1];
        self::assertStringStartsWith("SELECT *\n\t\t\tFROM settings", $query);
        $this->database->insert('settings', ['name' => 'alerting_evaluation_cursor', 'value' => '42']);
        $this->database->insert('settings', ['name' => 'alerting_batch_size', 'value' => '250']);

        $names = array_map('strval', array_column($this->database->fetchAllAssociative($query), 'name'));

        self::assertNotContains('alerting_evaluation_cursor', $names);
        self::assertContains('alerting_enable', $names);
        self::assertContains('alerting_batch_size', $names);
    }
}
