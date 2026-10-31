<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class SqlSaveNullableNumericDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        if (!getenv('KADUPUL_TEST_MYSQL_DSN')) self::markTestSkipped('KADUPUL_TEST_MYSQL_DSN is required for database contracts');
    }

    /** @return array<string, mixed> */
    private function fixture(string $mode): array
    {
        $fixture = dirname(__DIR__) . '/Fixtures/sql-save-nullable-native.php';
        $process = proc_open([PHP_BINARY, '-d', 'zend.exception_ignore_args=1', $fixture, $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr . $stdout);
        self::assertSame('', $stderr);

        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testNullableNumericTypesRoundTripMissingZeroAndNumericValues(): void
    {
        $result = $this->fixture('values');
        self::assertCount(8, $result['values']);
        $expected = ['empty' => null, 'null' => null, 'zero' => 0, 'string-zero' => 0, 'false' => 0,
            'float-zero' => 0, 'integer' => 7, 'numeric-string' => 12, 'negative' => -4, 'invalid' => 0];
        $count = 0;
        foreach ($result['values'] as $type => $rows) {
            foreach ($expected as $label => $value) {
                self::assertArrayHasKey($label, $rows);
                if ($value === null) self::assertNull($rows[$label], $type . ':' . $label);
                else {
                    self::assertNotNull($rows[$label], $type . ':' . $label);
                    self::assertSame((float) $value, (float) $rows[$label], $type . ':' . $label);
                }
                $count++;
            }
            if (in_array($type, ['float', 'double', 'decimal(20,4)'], true)) {
                self::assertSame(1.25, (float) $rows['fraction']);
                $count++;
            }
            if ($type === 'bigint') {
                self::assertSame('9223372036854775807', $rows['maximum']);
                $count++;
            }
        }
        self::assertSame(84, $count);
    }

    public function testDefaultsAndAutoIncrementRetainTheirOmissionAndZeroSemantics(): void
    {
        $result = $this->fixture('defaults');
        foreach (['empty', 'null'] as $label) {
            self::assertNull($result['rows'][$label]['optional']);
            self::assertSame(9, (int) $result['rows'][$label]['required_value']);
            self::assertSame(0, (int) $result['rows'][$label]['no_default']);
            self::assertSame('', $result['rows'][$label]['text_value']);
        }
        foreach (['false', 'zero'] as $label) {
            foreach (['optional', 'required_value', 'no_default'] as $column) {
                self::assertNotNull($result['rows'][$label][$column]);
                $expected = $label === 'false' && $column === 'required_value' ? 9 : 0;
                self::assertSame($expected, (int) $result['rows'][$label][$column]);
            }
        }
        self::assertSame(['optional' => 7, 'required_value' => 9, 'no_default' => 3, 'text_value' => 'stored'], $result['rows']['omitted']);
        self::assertTrue($result['identity']);
    }

    public function testUpdatesUseTheExplicitConnectionAndRespectCallerTransactionsAndOmittedFields(): void
    {
        $result = $this->fixture('updates');
        self::assertSame(1, (int) $result['id']);
        self::assertNull($result['cleared']);
        self::assertTrue($result['caller_transaction']);
        self::assertSame(41, (int) $result['default_unchanged']);
        self::assertSame(42, (int) $result['restored']);
        self::assertSame(['value' => 42, 'label' => 'changed'], $result['omitted_retained']);
    }

    public function testAnInvalidLaterColumnDoesNotPublishEarlierNormalizedValues(): void
    {
        $result = $this->fixture('failure');
        self::assertFalse($result['result']);
        self::assertSame(42, (int) $result['stored']);
        self::assertSame(['sql_save_key'], $result['messages']);
    }

    public function testShippedHostAndGraphSchemasRetainAnExplicitlyClearedNullableValue(): void
    {
        self::assertSame(['host' => null, 'graph_templates_graph' => null], $this->fixture('schema')['stored']);
    }

    public function testARejectedClearReportsFailureAndKeepsCallerOwnedWork(): void
    {
        $result = $this->fixture('refusal');
        self::assertFalse($result['result']);
        self::assertTrue($result['caller_transaction']);
        self::assertSame(['value' => 42, 'label' => 'owned'], $result['stored']);
    }

    public function testARejectedNonemptyNumberReportsFailureAndKeepsCallerOwnedWork(): void
    {
        $result = $this->fixture('negative-refusal');
        self::assertFalse($result['result']);
        self::assertTrue($result['caller_transaction']);
        self::assertSame(['value' => 42, 'label' => 'owned'], $result['stored']);
    }
}
