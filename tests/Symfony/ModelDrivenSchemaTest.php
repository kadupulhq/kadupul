<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests;

use Kadupul\Platform\Domain\Schema\AuditSchemaDump;
use Kadupul\Platform\Domain\Schema\BaselineColumn;
use Kadupul\Platform\Domain\Schema\BaselineIndex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The model-driven monitoring tables are defined three times: cacti.sql for
 * a fresh install, the 1.2.37 step for an upgrade, and the audit baseline.
 * ModelDrivenSchemaDatabaseTest proves the same on a real server.
 */
final class ModelDrivenSchemaTest extends TestCase
{
    private const string STEP = '1.2.37';

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/install/upgrades/1_2_37.php';
    }

    /** @return iterable<string, array{string}> */
    public static function tables(): iterable
    {
        yield 'bindings' => ['kadupul_metric_binding'];
        yield 'ownership' => ['kadupul_writer_ownership'];
    }

    #[DataProvider('tables')]
    public function testFreshInstallAndUpgradeCreateTheSameTable(string $table): void
    {
        $upgrade = upgrade_model_driven_monitoring_tables()[$table]['create'];
        self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS ' . $table . ' (', $upgrade, 'an upgrade rerun must not fail on a table it already created');

        self::assertSame(self::normalized(self::freshInstall($table)), self::normalized($upgrade));
    }

    #[DataProvider('tables')]
    public function testTheUpgradeConfirmsTheColumnsItCreates(string $table): void
    {
        $definition = upgrade_model_driven_monitoring_tables()[$table];

        self::assertSame(self::columnNames($definition['create']), $definition['columns']);
        self::assertSame(self::keyColumns($definition['create'], 'PRIMARY KEY'), $definition['primary']);
    }

    #[DataProvider('tables')]
    public function testTheAuditBaselineListsTheSameColumnsAndKeys(string $table): void
    {
        $dump = file_get_contents(dirname(__DIR__, 2) . '/docs/audit_schema.sql');
        self::assertIsString($dump);
        $baseline = AuditSchemaDump::parse($dump);
        $create = self::freshInstall($table);

        self::assertTrue($baseline->hasTable($table));
        self::assertSame(self::columnNames($create), array_map(static fn(BaselineColumn $c): string => $c->field, $baseline->columns($table)));
        self::assertSame(range(1, count(self::columnNames($create))), array_map(static fn(BaselineColumn $c): int => $c->sequence, $baseline->columns($table)));
        $keys = [];
        foreach ($baseline->indexes($table) as $index) {
            self::assertInstanceOf(BaselineIndex::class, $index);
            $keys[$index->keyName][$index->seqInIndex] = $index->columnName;
            self::assertSame($index->keyName === 'PRIMARY' ? 0 : 1, $index->nonUnique);
        }
        $expected = ['PRIMARY' => self::keyColumns($create, 'PRIMARY KEY')];
        if (preg_match('/^\s*KEY (\w+) \(/m', $create, $named) === 1) {
            $expected[$named[1]] = self::keyColumns($create, 'KEY ' . $named[1]);
        }
        ksort($keys);
        ksort($expected);
        self::assertSame($expected, array_map(static fn(array $columns): array => array_values($columns), $keys));
    }

    public function testTheStepIsReachableAboveTheDeployedRelease(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertSame(self::STEP, trim((string) file_get_contents($root . '/include/cacti_version')));
        self::assertMatchesRegularExpression("/^\\s*'1\\.2\\.36'\\s*=>\\s*'0103',\\n\\s*'1\\.2\\.37'\\s*=>\\s*'0103',\\n\\);/m", (string) file_get_contents($root . '/include/global_arrays.php'));
        self::assertTrue(function_exists('upgrade_to_1_2_37'));
    }

    private static function freshInstall(string $table): string
    {
        $sql = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        self::assertIsString($sql);
        self::assertSame(1, preg_match_all('/^CREATE TABLE ' . $table . ' \(.*?^\) [^;\n]*;/ms', $sql, $found), $table . ' must be created once in cacti.sql');

        return rtrim($found[0][0], ';');
    }

    private static function normalized(string $create): string
    {
        return (string) preg_replace('/\s+/', ' ', str_replace('CREATE TABLE IF NOT EXISTS ', 'CREATE TABLE ', $create));
    }

    /** @return list<string> */
    private static function columnNames(string $create): array
    {
        preg_match_all('/^\s+([a-z_]+) [a-z]/m', $create, $found);

        return array_values(array_filter($found[1], static fn(string $name): bool => !in_array($name, ['PRIMARY', 'KEY'], true)));
    }

    /** @return list<string> */
    private static function keyColumns(string $create, string $key): array
    {
        self::assertSame(1, preg_match('/^\s+' . $key . ' \(([^)]+)\)/m', $create, $found), $key . ' missing');

        return array_map('trim', explode(',', $found[1]));
    }
}
