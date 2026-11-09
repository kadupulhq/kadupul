<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * The alerting rate source against what the real dsstats_poller_output()
 * (lib/dsstats.php) writes on MariaDB or MySQL, with the shipped cacti.sql
 * tables created in a schema this test owns and drops. They cannot be
 * temporary tables: MySQL cannot open one twice in a query. The settings say Boost
 * is on: process_poller_output() still calls the writer then (lib/poller.php
 * :1238-1290; BoostDsstatsHandoffNativeTest runs that path), and the writer
 * reads no Boost setting, so these are the rows a Boost installation holds.
 */

namespace AlertRateSourceDatabaseTest;

$applicationLoader = require dirname(__DIR__, 2) . '/include/vendor/autoload.php';
$applicationLoader->unregister();
$applicationLoader->register(false);

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Alerting\Domain\Evaluation\Observation;
use Kadupul\Alerting\Domain\Rule\SeriesKey;
use Kadupul\Alerting\Infrastructure\Dbal\DsstatsRateSource;
use PHPUnit\Framework\TestCase;

const TABLES = ['settings', 'data_source_stats_hourly_cache', 'data_source_stats_hourly_last', 'data_template_data', 'data_template_rrd', 'graph_templates_item', 'data_input_fields'];

/** The writer's database calls, over the test's connection. */
function db(): \PDO
{
    $native = $GLOBALS['alert_rate_source_db']->getNativeConnection();
    \assert($native instanceof \PDO);

    return $native;
}
function read_config_option($name)
{
    $statement = db()->prepare('SELECT value FROM settings WHERE name = ?');
    $statement->execute([$name]);
    $value = $statement->fetchColumn();

    return $value === false ? '' : $value;
}
function cacti_sizeof($value)
{
    return is_array($value) || $value instanceof \Countable ? count($value) : 0;
}
function db_fetch_assoc($sql)
{
    return db()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
}
function db_fetch_cell_prepared($sql, $params = [])
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $value = $statement->fetchColumn();

    return $value === false ? '' : $value;
}
function db_execute($sql)
{
    return db()->exec($sql) !== false;
}
function cacti_log($message, ...$arguments)
{
    $GLOBALS['alert_rate_source_log'][] = $message;
}

if (!function_exists(__NAMESPACE__ . '\dsstats_poller_output')) {
    $root = dirname(__DIR__, 2);
    foreach (['/lib/dsstats.php' => 'dsstats_poller_output', '/lib/functions.php' => 'array_rekey'] as $file => $function) {
        $source = file_get_contents($root . $file);
        if (!is_string($source)) {
            throw new \RuntimeException('Unable to read ' . $file);
        }
        eval('namespace ' . __NAMESPACE__ . ';' . test_php_function_source($source, $function));
    }
    // set_error_handler() resolves this name globally.
    if (!function_exists('dsstats_error_handler')) {
        eval('function dsstats_error_handler($errno, $errmsg) { return false; }');
    }
}

final class AlertRateSourceDatabaseTest extends TestCase
{
    private const int COUNTER_ID = 501;
    private const int GAUGE_ID = 502;

    private Connection $database;
    private string $schema = '';

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('Set KADUPUL_TEST_MYSQL_DSN to a task-owned MariaDB or MySQL server; these rate source tests were not run.');
        }
        parse_str(str_replace(';', '&', substr($dsn, strlen('mysql:'))), $target);
        $prefix = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ? 'KADUPUL_TEST_MYSQL_ADMIN_' : 'KADUPUL_TEST_MYSQL_';
        $this->schema = 'alert_rate_' . bin2hex(random_bytes(6));
        $this->database = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => is_string($target['host'] ?? null) ? $target['host'] : '127.0.0.1',
            'port' => (int) ($target['port'] ?? 3306),
            'user' => (string) (getenv($prefix . 'USER') ?: 'root'),
            'password' => (string) (getenv($prefix . 'PASSWORD') ?: ''),
            'charset' => 'utf8mb4',
        ]);
        $this->database->executeStatement('CREATE DATABASE `' . $this->schema . '`');
        $this->database->executeStatement('USE `' . $this->schema . '`');
        // The poller's own session (lib/database.php:190-221): no strict or zero-date
        // modes, so the shipped zero-date DDL loads on MySQL too, and PHP's zone,
        // as Cacti requires, because the writer formats times with date().
        $this->database->executeStatement("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION,ALLOW_INVALID_DATES'");
        $this->database->executeStatement('SET time_zone = ?', [date('P')]);
        $GLOBALS['alert_rate_source_db'] = $this->database;
        $GLOBALS['alert_rate_source_log'] = [];

        $sql = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        self::assertIsString($sql);
        foreach (TABLES as $table) {
            self::assertSame(1, preg_match('/^CREATE TABLE `?' . $table . '`? \(.*?^\s*\) [^;\n]*;/ms', $sql, $create), $table);
            $this->database->executeStatement(rtrim($create[0], ';'));
        }
        self::assertSame('MEMORY', $this->engine('data_source_stats_hourly_last'));
        self::assertSame('MEMORY', $this->engine('data_source_stats_hourly_cache'));

        foreach (['dsstats_enable' => 'on', 'boost_rrd_update_enable' => 'on', 'boost_redirect' => 'on'] as $name => $value) {
            $this->database->insert('settings', ['name' => $name, 'value' => $value]);
        }
        // A COUNTER on a 300-second step and a GAUGE on a 60-second step.
        foreach ([[self::COUNTER_ID, 'traffic_in', 2, 300], [self::GAUGE_ID, 'load_1min', 1, 60]] as [$id, $name, $type, $step]) {
            $this->database->insert('data_template_data', ['local_data_id' => $id, 'rrd_step' => $step, 'name' => $name]);
            $this->database->insert('data_template_rrd', ['local_data_id' => $id, 'data_source_name' => $name, 'data_source_type_id' => $type, 'rrd_maximum' => 'U']);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            if ($this->schema !== '') {
                $this->database->executeStatement('DROP DATABASE IF EXISTS `' . $this->schema . '`');
            }
            $this->database->close();
        }
    }

    private function engine(string $table): string
    {
        $create = $this->database->fetchAssociative('SHOW CREATE TABLE ' . $table);
        self::assertIsArray($create);
        self::assertSame(1, preg_match('/ENGINE=(\w+)/', (string) end($create), $engine));

        return strtoupper($engine[1]);
    }

    /** One poller cycle through the real writer, shaped like process_poller_output()'s array. */
    private static function poll(int $localDataId, string $name, int $time, string $reading): void
    {
        $samples = ['/rra/' . $localDataId . '.rrd' => ['local_data_id' => $localDataId, 'times' => [$time => [$name => $reading]]]];
        dsstats_poller_output($samples);
    }

    public function testReadsCalculatedColumnWithBoostEnabled(): void
    {
        $t0 = time() - 600;
        self::poll(self::COUNTER_ID, 'traffic_in', $t0, '1000');
        self::poll(self::COUNTER_ID, 'traffic_in', $t0 + 300, '4000');
        self::poll(self::GAUGE_ID, 'load_1min', $t0 + 540, '0.75');

        // The writer filled both MEMORY tables while Boost is on.
        self::assertEquals(
            ['value' => 4000.0, 'calculated' => 10.0],
            array_map('floatval', $this->database->fetchAssociative('SELECT value, calculated FROM data_source_stats_hourly_last WHERE local_data_id = ?', [self::COUNTER_ID]) ?: []),
        );
        self::assertSame(2, (int) $this->database->fetchOne('SELECT COUNT(*) FROM data_source_stats_hourly_cache WHERE local_data_id = ?', [self::COUNTER_ID]));

        $source = new DsstatsRateSource($this->database);
        self::assertTrue($source->available());
        $counter = new SeriesKey(self::COUNTER_ID, 'traffic_in');
        $gauge = new SeriesKey(self::GAUGE_ID, 'load_1min');

        // `calculated` (3000 / 300), not the raw reading 4000; time is the sample's.
        self::assertEquals(
            [$counter->id() => new Observation(10.0, $t0 + 300, 300), $gauge->id() => new Observation(0.75, $t0 + 540, 60)],
            $source->currentRates([$counter, $gauge]),
        );
        self::assertSame([], $GLOBALS['alert_rate_source_log']);
    }

    public function testTheTimeDoesNotDependOnTheReadingSessionTimeZone(): void
    {
        $t0 = time() - 60;
        self::poll(self::GAUGE_ID, 'load_1min', $t0, '2');
        $gauge = new SeriesKey(self::GAUGE_ID, 'load_1min');

        $this->database->executeStatement("SET time_zone = '+09:00'");

        self::assertEquals([$gauge->id() => new Observation(2.0, $t0, 60)], (new DsstatsRateSource($this->database))->currentRates([$gauge]));
    }

    public function testNoRateIsAbsentNeverZero(): void
    {
        $t0 = time() - 60;
        // A counter's first cycle has no previous reading, so `calculated` is NULL.
        self::poll(self::COUNTER_ID, 'traffic_in', $t0, '1000');
        // 'U' stores NULL for every type.
        self::poll(self::GAUGE_ID, 'load_1min', $t0, 'U');
        self::assertNull($this->database->fetchOne('SELECT calculated FROM data_source_stats_hourly_last WHERE local_data_id = ?', [self::COUNTER_ID]));

        $missing = new SeriesKey(999, 'traffic_in');
        $otherName = new SeriesKey(self::COUNTER_ID, 'traffic_out');
        $wrongCase = new SeriesKey(self::GAUGE_ID, 'LOAD_1MIN');

        self::assertSame([], (new DsstatsRateSource($this->database))->currentRates([
            new SeriesKey(self::COUNTER_ID, 'traffic_in'), new SeriesKey(self::GAUGE_ID, 'load_1min'), $missing, $otherName, $wrongCase,
        ]));
    }

    public function testACacheRowThatDisagreesWithTheLastRateHasNoKnownTime(): void
    {
        $t0 = time() - 120;
        self::poll(self::GAUGE_ID, 'load_1min', $t0, '3');
        $gauge = new SeriesKey(self::GAUGE_ID, 'load_1min');
        $source = new DsstatsRateSource($this->database);
        self::assertEquals([$gauge->id() => new Observation(3.0, $t0, 60)], $source->currentRates([$gauge]));

        // The writer's cache insert landed and its last-value insert has not yet.
        $this->database->executeStatement(
            'INSERT INTO data_source_stats_hourly_cache (local_data_id, rrd_name, time, value) VALUES (?, ?, FROM_UNIXTIME(?), 4)',
            [self::GAUGE_ID, 'load_1min', $t0 + 60],
        );
        self::assertSame([], $source->currentRates([$gauge]), 'An old rate must not take a newer sample time.');

        self::poll(self::GAUGE_ID, 'load_1min', $t0 + 60, '4');
        self::assertEquals([$gauge->id() => new Observation(4.0, $t0 + 60, 60)], $source->currentRates([$gauge]));
    }

    public function testUnavailableWhenDsstatsIsOffWithBoostOn(): void
    {
        $this->database->update('settings', ['value' => ''], ['name' => 'dsstats_enable']);
        $source = new DsstatsRateSource($this->database);

        self::assertFalse($source->available());

        // And the writer then stores nothing, so nothing could be read.
        self::poll(self::GAUGE_ID, 'load_1min', time(), '1');
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM data_source_stats_hourly_last'));
    }
}
