<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Alerting\Infrastructure\Dbal\DsstatsRateSource;
use PHPUnit\Framework\TestCase;

/**
 * The availability check only reads a setting and probes two tables, so SQLite
 * shows every branch. Reading rates needs MariaDB or MySQL and is covered by
 * tests/integration/AlertRateSourceDatabaseTest.php.
 */
final class DsstatsRateSourceAvailabilityTest extends TestCase
{
    private Connection $database;

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->database->executeStatement('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value VARCHAR(4096) NOT NULL)');
        $this->database->executeStatement('CREATE TABLE data_source_stats_hourly_last (local_data_id INT, rrd_name VARCHAR(19), value DOUBLE, calculated DOUBLE)');
        $this->database->executeStatement('CREATE TABLE data_source_stats_hourly_cache (local_data_id INT, rrd_name VARCHAR(19), time TIMESTAMP, value DOUBLE)');
    }

    public function testAvailableOnlyWhenDsstatsIsOn(): void
    {
        $source = new DsstatsRateSource($this->database);

        // The setting's default is off, as read_config_option() reads it.
        self::assertFalse($source->available());
        foreach (['', 'off', 'ON', ' on'] as $value) {
            $this->database->executeStatement('REPLACE INTO settings (name, value) VALUES (?, ?)', ['dsstats_enable', $value]);
            self::assertFalse($source->available(), var_export($value, true));
        }
        $this->database->executeStatement('REPLACE INTO settings (name, value) VALUES (?, ?)', ['dsstats_enable', 'on']);
        self::assertTrue($source->available());
    }

    public function testUnavailableWhenEitherDsstatsTableIsMissing(): void
    {
        foreach (['data_source_stats_hourly_last', 'data_source_stats_hourly_cache'] as $table) {
            $this->setUp();
            $this->database->executeStatement("INSERT INTO settings (name, value) VALUES ('dsstats_enable', 'on')");
            $this->database->executeStatement('DROP TABLE ' . $table);
            self::assertFalse((new DsstatsRateSource($this->database))->available(), $table);
        }
    }

    public function testNoKeysReadNothing(): void
    {
        self::assertSame([], (new DsstatsRateSource($this->database))->currentRates([]));
    }

    public function testRefusesAnUnreadableSettingsTable(): void
    {
        $this->database->executeStatement('DROP TABLE settings');

        // A broken database is an error (exit 1), not "inactive" (exit 2).
        $this->expectException(\Doctrine\DBAL\Exception::class);
        (new DsstatsRateSource($this->database))->available();
    }
}
