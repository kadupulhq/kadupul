<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Alerting\Application\AlertingConfiguration;
use Kadupul\Alerting\Infrastructure\Dbal\DbalAlertingSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DbalAlertingSettingsTest extends TestCase
{
    private Connection $database;

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->database->executeStatement('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value VARCHAR(4096) NOT NULL)');
    }

    private function set(string $name, string $value): void
    {
        $this->database->executeStatement('REPLACE INTO settings (name, value) VALUES (?, ?)', [$name, $value]);
    }

    public function testMissingSettingsAreOffWithTheDefaults(): void
    {
        // An upgraded installation has no rows: alerting stays off (ADR 0011 decision 10).
        self::assertEquals(
            new AlertingConfiguration(false, AlertingConfiguration::DEFAULT_BATCH_SIZE, AlertingConfiguration::DEFAULT_BUDGET_SECONDS),
            (new DbalAlertingSettings($this->database))->current(),
        );
    }

    public function testOnlyOnEnables(): void
    {
        foreach (['' => false, 'off' => false, 'ON' => false, ' on' => false, 'on' => true] as $value => $enabled) {
            $this->set('alerting_enable', (string) $value);
            self::assertSame($enabled, (new DbalAlertingSettings($this->database))->current()->enabled, var_export($value, true));
        }
    }

    public function testStoredBatchSizeAndBudgetAreUsed(): void
    {
        $this->set('alerting_enable', 'on');
        $this->set('alerting_batch_size', '250');
        $this->set('alerting_time_budget', '60');

        self::assertEquals(new AlertingConfiguration(true, 250, 60.0), (new DbalAlertingSettings($this->database))->current());
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformed(): iterable
    {
        foreach (['alerting_batch_size', 'alerting_time_budget'] as $name) {
            foreach (['', '0', '-1', '1.5', '1e3', ' 100', '100 ', '0100', 'abc', '99999999999999999999'] as $value) {
                yield $name . ' ' . var_export($value, true) => [$name, $value];
            }
        }
        yield 'batch above its cap' => ['alerting_batch_size', '10001'];
        yield 'budget above its cap' => ['alerting_time_budget', '3601'];
    }

    #[DataProvider('malformed')]
    public function testAMalformedOrOutOfRangeValueFallsBackToItsDefault(string $name, string $value): void
    {
        $this->set($name, $value);

        $current = (new DbalAlertingSettings($this->database))->current();

        self::assertSame(AlertingConfiguration::DEFAULT_BATCH_SIZE, $current->batchSize);
        self::assertSame(AlertingConfiguration::DEFAULT_BUDGET_SECONDS, $current->budgetSeconds);
    }

    public function testAnUnreadableSettingsTableIsAnError(): void
    {
        $this->database->executeStatement('DROP TABLE settings');

        $this->expectException(\Doctrine\DBAL\Exception::class);
        (new DbalAlertingSettings($this->database))->current();
    }

    public function testTheShippedSettingsMatchTheDefaults(): void
    {
        // include/global_settings.php needs the legacy bootstrap to load, so its
        // alerting fields are read as source; the adapter is the runtime truth.
        $source = file_get_contents(dirname(__DIR__, 4) . '/include/global_settings.php');
        self::assertIsString($source);
        foreach (['alerting_enable' => "''", 'alerting_batch_size' => "'" . AlertingConfiguration::DEFAULT_BATCH_SIZE . "'", 'alerting_time_budget' => "'" . (int) AlertingConfiguration::DEFAULT_BUDGET_SECONDS . "'"] as $name => $default) {
            self::assertSame(1, preg_match("/'" . $name . "' => array\\((.*?)\\n        \\),/s", $source, $field), $name);
            self::assertSame(1, preg_match("/'default' => " . preg_quote($default, '/') . ",?\\n/", $field[1] . "\n"), $name . ' default');
        }
    }
}
