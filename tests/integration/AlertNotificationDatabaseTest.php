<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * The notification queue on MariaDB or MySQL: the evaluator queues inside
 * the rule's transaction and DeliverNotifications claims, sends and settles
 * rows, over the shipped cacti.sql tables in a schema this test owns and
 * drops. Mail goes to a recording port; nothing leaves the process.
 */

namespace AlertNotificationDatabaseTest;

$applicationLoader = require dirname(__DIR__, 2) . '/include/vendor/autoload.php';
$applicationLoader->unregister();
$applicationLoader->register(false);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Kadupul\Alerting\Application\Command\NotifyAdministrator;
use Kadupul\Alerting\Application\DeliverNotifications;
use Kadupul\Alerting\Application\DeliveryReport;
use Kadupul\Alerting\Application\EvaluateRules;
use Kadupul\Alerting\Application\EvaluationReport;
use Kadupul\Alerting\Application\MailDeliveryFailed;
use Kadupul\Alerting\Application\Port\ClaimedNotification;
use Kadupul\Alerting\Application\Port\NotificationQueue;
use Kadupul\Alerting\Application\Port\RuleRepository;
use Kadupul\Alerting\Application\Port\RuleStateStore;
use Kadupul\Alerting\Application\Port\UnusableRuleRecord;
use Kadupul\Alerting\Domain\AdministratorNotificationPolicy;
use Kadupul\Alerting\Domain\Evaluation\RuleState;
use Kadupul\Alerting\Domain\Evaluation\Transition;
use Kadupul\Alerting\Domain\Rule\AlertRule;
use Kadupul\Alerting\Domain\Incident\Incident;
use Kadupul\Alerting\Domain\Incident\IncidentStatus;
use Kadupul\Alerting\Infrastructure\Dbal\DbalEvaluationCursor;
use Kadupul\Alerting\Infrastructure\Dbal\DbalIncidentRepository;
use Kadupul\Alerting\Infrastructure\Dbal\DbalNotificationQueue;
use Kadupul\Alerting\Infrastructure\Dbal\DbalRuleRepository;
use Kadupul\Alerting\Infrastructure\Dbal\DbalRuleStateStore;
use Kadupul\Alerting\Infrastructure\Dbal\DbalTransactionRunner;
use Kadupul\Alerting\Infrastructure\Dbal\DsstatsBaselineSource;
use Kadupul\Alerting\Infrastructure\Dbal\DsstatsRateSource;
use Kadupul\Tests\Alerting\Fakes\FixedAdministrator;
use Kadupul\Tests\Alerting\Fakes\MockClockAdapter;
use Kadupul\Tests\Alerting\Fakes\RecordingLogger;
use Kadupul\Tests\Alerting\Fakes\StoredIncidents;
use Kadupul\Tests\Alerting\Fakes\RecordingMailDelivery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

const TABLES = ['alert_rule', 'alert_rule_state', 'alert_incident', 'alert_notification', 'alert_baseline_sample', 'settings', 'host', 'data_local', 'data_template_data', 'data_template_rrd', 'data_source_stats_hourly_cache', 'data_source_stats_hourly_last'];

/** Writes the notification row, then fails, as a lost connection after the insert would. */
final readonly class FailingNotifications implements NotificationQueue
{
    public function __construct(private NotificationQueue $inner) {}

    public function enqueue(int $incidentId, Transition $kind, float $value, float $threshold, int $queuedAt): void
    {
        $this->inner->enqueue($incidentId, $kind, $value, $threshold, $queuedAt);
        throw new \RuntimeException('injected failure after the notification insert');
    }

    public function failExpiredClaims(int $now, int $maxAttempts): int
    {
        return $this->inner->failExpiredClaims($now, $maxAttempts);
    }

    public function due(int $now, int $maxAttempts, int $limit): array
    {
        return $this->inner->due($now, $maxAttempts, $limit);
    }

    public function claim(int $id, int $now, int $maxAttempts, int $leaseUntil): ?ClaimedNotification
    {
        return $this->inner->claim($id, $now, $maxAttempts, $leaseUntil);
    }

    public function markSent(ClaimedNotification $claim, int $sentAt): bool
    {
        return $this->inner->markSent($claim, $sentAt);
    }

    public function markSuppressed(ClaimedNotification $claim, string $reason): bool
    {
        return $this->inner->markSuppressed($claim, $reason);
    }

    public function markAttemptFailed(ClaimedNotification $claim, string $error, ?int $retryAt): bool
    {
        return $this->inner->markAttemptFailed($claim, $error, $retryAt);
    }
}

/** Runs $after once batchAfter() has read the batch, as a delete by another session would. */
final class RacingRules implements RuleRepository
{
    public function __construct(private readonly RuleRepository $inner, private ?\Closure $after) {}

    public function batchAfter(int $afterRuleId, int $limit): array
    {
        $batch = $this->inner->batchAfter($afterRuleId, $limit);
        if ($this->after !== null) {
            ($this->after)();
            $this->after = null;
        }

        return $batch;
    }

    public function lock(int $ruleId): AlertRule|UnusableRuleRecord|null
    {
        return $this->inner->lock($ruleId);
    }

    public function orphanedRuleIds(int $limit): array
    {
        return $this->inner->orphanedRuleIds($limit);
    }
}

/** Runs $during inside the rule's transaction, after the rule lock and before the state write. */
final class StatesWithAProbe implements RuleStateStore
{
    public function __construct(private readonly RuleStateStore $inner, public ?\Closure $during = null) {}

    public function load(array $ruleIds): array
    {
        return $this->inner->load($ruleIds);
    }

    public function lock(int $ruleId): RuleState|UnusableRuleRecord|null
    {
        return $this->inner->lock($ruleId);
    }

    public function save(int $ruleId, RuleState $state): void
    {
        if ($this->during !== null) {
            ($this->during)();
        }
        $this->inner->save($ruleId, $state);
    }

    public function delete(array $ruleIds): void
    {
        $this->inner->delete($ruleIds);
    }
}

final class AlertNotificationDatabaseTest extends TestCase
{
    private const int NOW = 1_800_000_000;
    private const int STEP = 300;

    private Connection $database;
    private ?Connection $other = null;
    private string $schema = '';
    private MockClock $clock;
    private RecordingMailDelivery $mail;
    private FixedAdministrator $administrator;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('Set KADUPUL_TEST_MYSQL_DSN to a task-owned MariaDB or MySQL server; these alert notification tests were not run.');
        }
        $this->schema = 'alert_notify_' . bin2hex(random_bytes(6));
        $this->database = self::connect($dsn);
        $this->database->executeStatement('CREATE DATABASE `' . $this->schema . '`');
        $this->use($this->database);

        $sql = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        self::assertIsString($sql);
        foreach (TABLES as $table) {
            self::assertSame(1, preg_match('/^CREATE TABLE `?' . $table . '`? \(.*?^\s*\) [^;\n]*;/ms', $sql, $create), $table);
            $this->database->executeStatement(rtrim($create[0], ';'));
        }
        $this->database->insert('settings', ['name' => 'dsstats_enable', 'value' => 'on']);

        $this->clock = new MockClock(new \DateTimeImmutable('@' . self::NOW));
        $this->mail = new RecordingMailDelivery();
        $this->administrator = new FixedAdministrator();
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        $this->other?->close();
        if (isset($this->database)) {
            if ($this->schema !== '') {
                $this->database->executeStatement('DROP DATABASE IF EXISTS `' . $this->schema . '`');
            }
            $this->database->close();
        }
    }

    private static function connect(string $dsn): Connection
    {
        parse_str(str_replace(';', '&', substr($dsn, strlen('mysql:'))), $target);
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

    private function use(Connection $connection): void
    {
        $connection->executeStatement('USE `' . $this->schema . '`');
        // The legacy session modes (lib/database.php) so the shipped zero-date DDL loads on MySQL too.
        $connection->executeStatement("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION,ALLOW_INVALID_DATES'");
        $connection->executeStatement("SET time_zone = '+00:00'");
    }

    /** A second session on the same schema, as another evaluator, deliverer or editor would hold. */
    private function other(): Connection
    {
        if ($this->other === null) {
            $this->other = self::connect((string) getenv('KADUPUL_TEST_MYSQL_DSN'));
            $this->use($this->other);
        }

        return $this->other;
    }

    private function evaluate(?NotificationQueue $notifications = null, ?RuleRepository $rules = null, ?RuleStateStore $states = null): EvaluationReport
    {
        $evaluate = new EvaluateRules(
            new DsstatsRateSource($this->database),
            new DsstatsBaselineSource($this->database),
            $rules ?? new DbalRuleRepository($this->database),
            $states ?? new DbalRuleStateStore($this->database),
            new DbalIncidentRepository($this->database),
            $notifications ?? new DbalNotificationQueue($this->database),
            new DbalEvaluationCursor($this->database),
            new MockClockAdapter($this->clock),
            new DbalTransactionRunner($this->database),
            $this->logger,
        );

        return $evaluate(100, 30.0);
    }

    private function deliver(?Connection $connection = null): DeliveryReport
    {
        $deliver = new DeliverNotifications(
            new DbalNotificationQueue($connection ?? $this->database),
            new NotifyAdministrator($this->administrator, $this->administrator, $this->mail),
            new MockClockAdapter($this->clock),
            $this->logger,
        );

        return $deliver(100, 30.0);
    }

    /** A device, a data source on it and a rule on its traffic_in (gt 90, clear 80). */
    private function watch(int $ruleId, int $localDataId, int $hostId): void
    {
        $this->database->insert('host', ['id' => $hostId, 'description' => 'device ' . $hostId, 'hostname' => '192.0.2.' . $hostId]);
        $this->database->insert('data_local', ['id' => $localDataId, 'host_id' => $hostId]);
        $this->database->insert('data_template_data', ['local_data_id' => $localDataId, 'rrd_step' => self::STEP, 'name' => 'ds ' . $localDataId]);
        $this->database->insert('data_template_rrd', ['local_data_id' => $localDataId, 'data_source_name' => 'traffic_in']);
        $this->database->insert('alert_rule', ['id' => $ruleId, 'local_data_id' => $localDataId, 'rrd_name' => 'traffic_in', 'comparator' => 'gt', 'trigger_threshold' => 90, 'clear_threshold' => 80, 'for_cycles' => 1]);
    }

    /** One dsstats cycle for the series, written as dsstats_poller_output() writes it. */
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

    private function at(int $time): void
    {
        $this->clock->sleep($time - $this->clock->now()->getTimestamp());
    }

    /** @return list<array<string, string|null>> every notification row, oldest first */
    private function rows(): array
    {
        return array_map(
            static fn(array $row): array => array_map(static fn(mixed $value): ?string => $value === null ? null : (string) $value, $row),
            $this->database->fetchAllAssociative('SELECT id, incident_id, kind, status, attempts, queued_at, next_attempt_at, sent_at, last_error, observed_value, threshold FROM alert_notification ORDER BY id'),
        );
    }

    private function stateRow(int $ruleId): array|false
    {
        return $this->database->fetchAssociative('SELECT incident_open, consecutive_breaches, last_observed_at FROM alert_rule_state WHERE rule_id = ?', [$ruleId]);
    }

    public function testAnOpenedAndARecoveredIncidentEachQueueOneRowWithTheirIncident(): void
    {
        $this->watch(1, 101, 10);
        $this->sample(101, self::NOW, 95.5);
        $this->evaluate();
        $this->sample(101, self::NOW + self::STEP, 70.25);
        $this->at(self::NOW + self::STEP + 5);
        $this->evaluate();

        $incident = StoredIncidents::forRule($this->database, 1);
        self::assertEquals([new Incident(1, 1, IncidentStatus::Closed, self::NOW, self::NOW + self::STEP)], $incident);
        self::assertSame([
            ['id' => '1', 'incident_id' => '1', 'kind' => 'opened', 'status' => 'queued', 'attempts' => '0', 'queued_at' => (string) self::NOW, 'next_attempt_at' => (string) self::NOW, 'sent_at' => null, 'last_error' => '', 'observed_value' => '95.5', 'threshold' => '90'],
            ['id' => '2', 'incident_id' => '1', 'kind' => 'recovered', 'status' => 'queued', 'attempts' => '0', 'queued_at' => (string) (self::NOW + self::STEP + 5), 'next_attempt_at' => (string) (self::NOW + self::STEP + 5), 'sent_at' => null, 'last_error' => '', 'observed_value' => '70.25', 'threshold' => '80'],
        ], $this->rows());
        self::assertSame([], $this->mail->sent, 'Evaluation queues; it never sends.');
    }

    public function testAFailedNotificationInsertRollsBackTheStateAndTheIncident(): void
    {
        $this->watch(1, 101, 10);
        $this->sample(101, self::NOW, 95.0);

        try {
            $this->evaluate(new FailingNotifications(new DbalNotificationQueue($this->database)));
            self::fail('The injected notification failure was not reported.');
        } catch (\RuntimeException $e) {
            self::assertSame('injected failure after the notification insert', $e->getMessage());
        }

        self::assertFalse($this->stateRow(1), 'The state saved first was rolled back.');
        self::assertSame([], StoredIncidents::forRule($this->database, 1), 'The incident insert was rolled back.');
        self::assertSame([], $this->rows(), 'The notification insert was rolled back.');
        self::assertFalse($this->database->isTransactionActive());

        $this->evaluate();
        self::assertCount(1, $this->rows(), 'The same observation opens and queues on the retry.');
        self::assertSame(IncidentStatus::Open, StoredIncidents::forRule($this->database, 1)[0]->status);
    }

    public function testARuleDeletedAfterItsBatchWasReadWritesNothing(): void
    {
        $this->watch(1, 101, 10);
        $this->watch(2, 102, 11);
        $this->sample(101, self::NOW, 95.0);
        $this->sample(102, self::NOW, 95.0);
        // Another session deletes rule 1 between the batch read and rule 1's transaction.
        $rules = new RacingRules(new DbalRuleRepository($this->database), function (): void {
            $this->other()->executeStatement('DELETE FROM alert_rule WHERE id = 1');
        });

        $report = $this->evaluate(rules: $rules);

        self::assertSame(1, $report->evaluated);
        self::assertFalse($this->stateRow(1), 'No state is recreated for the deleted rule.');
        self::assertSame([], StoredIncidents::forRule($this->database, 1));
        self::assertSame(['2'], array_map('strval', $this->database->fetchFirstColumn('SELECT i.rule_id FROM alert_notification n JOIN alert_incident i ON i.id = n.incident_id')), 'Only rule 2 is notified.');
        self::assertSame(0, $this->evaluate()->orphaned, 'Nothing was left for the orphan sweep.');
    }

    public function testTheRuleLockMakesADeleteWaitForTheRulesTransaction(): void
    {
        $this->watch(1, 101, 10);
        $this->sample(101, self::NOW, 95.0);
        $blocked = null;
        $states = new StatesWithAProbe(new DbalRuleStateStore($this->database), function () use (&$blocked): void {
            $other = $this->other();
            $other->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
            try {
                $other->executeStatement('DELETE FROM alert_rule WHERE id = 1');
                $blocked = false;
            } catch (LockWaitTimeoutException) {
                $blocked = true;
            }
        });

        $this->evaluate(states: $states);

        self::assertTrue($blocked, 'A delete from another session waited on the rule row lock.');
        self::assertCount(1, $this->rows());
        self::assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) FROM alert_rule'), 'The timed-out delete changed nothing.');
    }

    public function testTransportFailureKeepsNotificationQueuedWithAttemptCount(): void
    {
        $this->watch(1, 101, 10);
        $this->sample(101, self::NOW, 95.0);
        $this->evaluate();
        $this->mail->failures = array_fill(0, 5, new MailDeliveryFailed());

        self::assertEquals(new DeliveryReport(retrying: 1), $this->deliver());

        $row = $this->rows()[0];
        self::assertSame(['queued', '1', (string) (self::NOW + 60), null], [$row['status'], $row['attempts'], $row['next_attempt_at'], $row['sent_at']]);
        self::assertSame('MailDeliveryFailed: Mail could not be confirmed. Check the installation mail settings and SMTP server logs before retrying.', $row['last_error']);
        self::assertSame(IncidentStatus::Open, StoredIncidents::forRule($this->database, 1)[0]->status, 'The incident is kept.');

        // Attempts 2 to 4 each schedule the next; the fifth is the last.
        $next = self::NOW + 60;
        foreach (array_slice(DeliverNotifications::RETRY_DELAYS, 1) as $delay) {
            $this->at($next);
            self::assertEquals(new DeliveryReport(retrying: 1), $this->deliver());
            $next += $delay;
        }
        $this->at($next);
        self::assertEquals(new DeliveryReport(failed: 1), $this->deliver());
        $row = $this->rows()[0];
        self::assertSame(['failed', '5', null], [$row['status'], $row['attempts'], $row['sent_at']]);
        self::assertSame('error', $this->logger->records[0]['level']);
        self::assertSame(1, $this->logger->records[0]['context']['notification']);

        $this->at($next + 86_400);
        self::assertEquals(new DeliveryReport(), $this->deliver(), 'A failed row is not retried.');
        self::assertSame([], $this->mail->failures);
        self::assertSame([], $this->mail->sent);
    }

    public function testADisabledAdministratorNotificationSuppressesEachRowOnce(): void
    {
        $this->watch(1, 101, 10);
        $this->watch(2, 102, 11);
        $this->sample(101, self::NOW, 95.0);
        $this->sample(102, self::NOW, 96.0);
        $this->evaluate();
        // notify_admin off, Cacti's default.
        $this->administrator->policy = new AdministratorNotificationPolicy(1, false);

        self::assertEquals(new DeliveryReport(suppressed: 2), $this->deliver());

        foreach ($this->rows() as $row) {
            self::assertSame(['suppressed', '1', 'administrator notification: Disabled', null], [$row['status'], $row['attempts'], $row['last_error'], $row['sent_at']]);
        }
        self::assertSame([['level' => 'warning', 'message' => '{count} alert notifications were not sent: administrator notification is {status}.', 'context' => ['count' => 2, 'status' => 'Disabled']]], $this->logger->records);

        $this->administrator->policy = new AdministratorNotificationPolicy(1, true);
        $this->at(self::NOW + 86_400);
        self::assertEquals(new DeliveryReport(), $this->deliver(), 'A suppressed row is final.');
        self::assertSame([], $this->mail->sent);
        self::assertCount(1, $this->logger->records);
    }

    public function testNotificationBodyContainsNoCredentialsOrRawSql(): void
    {
        $this->watch(1, 101, 10);
        $this->database->update('host', [
            'description' => 'core <b>edge</b>',
            'snmp_community' => 'community-s3cret',
            'snmp_username' => 'v3-user-s3cret',
            'snmp_password' => 'auth-s3cret',
            'snmp_priv_passphrase' => 'priv-s3cret',
        ], ['id' => 10]);
        // A title that substitutes the community, as |host_snmp_community| would.
        $this->database->update('data_template_data', ['name_cache' => 'title community-s3cret'], ['local_data_id' => 101]);
        $this->database->insert('settings', ['name' => 'settings_smtp_password', 'value' => 'smtp-s3cret']);
        $this->sample(101, self::NOW, 95.5);
        $this->evaluate();

        self::assertEquals(new DeliveryReport(sent: 1), $this->deliver());

        $body = $this->mail->sent[0]['html'];
        self::assertSame(
            "Alert rule 1 opened an incident.<br>\nRule: alert rule 1<br>\nSeries: device core &lt;b&gt;edge&lt;/b&gt;, data source 101, traffic_in<br>\n"
            . "Transition: opened<br>\nValue: 95.5<br>\nTrigger threshold: 90<br>\nTime: 2027-01-15 08:00:00 UTC<br>\nIncident: 1, on the Alert Incidents page (app.php/alerting/incidents/1 on the Kadupul site)",
            $body,
        );
        foreach (['s3cret', 'SELECT', 'alert_notification', 'mysql:', $this->schema] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $body . $this->mail->sent[0]['subject']);
        }
        self::assertSame(['sent', '1', (string) self::NOW], [$this->rows()[0]['status'], $this->rows()[0]['attempts'], $this->rows()[0]['sent_at']]);
    }

    public function testRecoveryAfterOrphanSendsNothing(): void
    {
        $this->watch(1, 101, 10);
        $this->sample(101, self::NOW, 95.0);
        $this->evaluate();
        self::assertSame(1, $this->deliver()->sent);

        // The data source is deleted while the incident is open; its last rates stay in dsstats.
        $this->database->executeStatement('DELETE FROM data_local WHERE id = 101');
        $this->at(self::NOW + 60);
        self::assertSame(1, $this->evaluate()->orphaned);
        // A rate that would recover the rule arrives after the orphaning.
        $this->sample(101, self::NOW + self::STEP, 10.0);
        $this->at(self::NOW + self::STEP);
        $this->evaluate();

        self::assertEquals(new DeliveryReport(), $this->deliver());
        self::assertCount(1, $this->mail->sent, 'Only the opening was mailed.');
        self::assertSame(['opened'], array_column($this->rows(), 'kind'));
        self::assertSame(IncidentStatus::Orphaned, StoredIncidents::forRule($this->database, 1)[0]->status);
    }

    public function testTwoDeliverersOnSeparateSessionsNeverSendTheSameRow(): void
    {
        $this->watch(1, 101, 10);
        $this->watch(2, 102, 11);
        $this->sample(101, self::NOW, 95.0);
        $this->sample(102, self::NOW, 96.0);
        $this->evaluate();
        $inner = null;
        // While the first deliverer is inside its first send, a second one runs on its own session.
        $this->mail->during = function () use (&$inner): void {
            if ($inner === null) {
                $inner = new DeliveryReport();
                $inner = $this->deliver($this->other());
            }
        };

        $outer = $this->deliver();

        self::assertSame([1, 1], [$outer->sent, $inner->sent]);
        self::assertCount(2, $this->mail->sent);
        self::assertNotSame($this->mail->sent[0]['subject'], $this->mail->sent[1]['subject'], 'Each rule was mailed once.');
        self::assertSame(['sent', 'sent'], array_column($this->rows(), 'status'));
        self::assertSame(['1', '1'], array_column($this->rows(), 'attempts'));
    }

    public function testDeliverersThatReadTheSameDueRowsClaimEachOnce(): void
    {
        $this->watch(1, 101, 10);
        $this->sample(101, self::NOW, 95.0);
        $this->evaluate();
        $mine = new DbalNotificationQueue($this->database);
        $theirs = new DbalNotificationQueue($this->other());
        $lease = self::NOW + DeliverNotifications::LEASE_SECONDS;

        // Both sessions see the row due before either claims it.
        self::assertSame([1], $mine->due(self::NOW, DeliverNotifications::MAX_ATTEMPTS, 10));
        self::assertSame([1], $theirs->due(self::NOW, DeliverNotifications::MAX_ATTEMPTS, 10));
        $claim = $mine->claim(1, self::NOW, DeliverNotifications::MAX_ATTEMPTS, $lease);

        self::assertNotNull($claim);
        self::assertNull($theirs->claim(1, self::NOW, DeliverNotifications::MAX_ATTEMPTS, $lease), 'The second claim finds the row held.');
        self::assertTrue($mine->markSent($claim, self::NOW));
        self::assertNull($theirs->claim(1, $lease, DeliverNotifications::MAX_ATTEMPTS, $lease + 900), 'A sent row is never claimed again.');
        self::assertSame(['sent', '1'], [$this->rows()[0]['status'], $this->rows()[0]['attempts']]);
    }

    public function testAnExpiredClaimIsDeliveredAgain(): void
    {
        $this->watch(1, 101, 10);
        $this->sample(101, self::NOW, 95.0);
        $this->evaluate();
        // A deliverer claimed the row and died before it reported back.
        self::assertNotNull((new DbalNotificationQueue($this->other()))->claim(1, self::NOW, DeliverNotifications::MAX_ATTEMPTS, self::NOW + DeliverNotifications::LEASE_SECONDS));

        $this->at(self::NOW + DeliverNotifications::LEASE_SECONDS - 1);
        self::assertEquals(new DeliveryReport(), $this->deliver());
        $this->at(self::NOW + DeliverNotifications::LEASE_SECONDS);
        self::assertEquals(new DeliveryReport(sent: 1), $this->deliver());
        self::assertSame(['sent', '2'], [$this->rows()[0]['status'], $this->rows()[0]['attempts']]);
    }
}
