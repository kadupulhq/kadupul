<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class PlacementTransactionDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        if (!getenv('KADUPUL_TEST_MYSQL_DSN')) {
            self::markTestSkipped('KADUPUL_TEST_MYSQL_DSN is required for database contracts');
        }
    }

    /** @dataProvider outcomes */
    public function testProductionPlacementWrappersOwnOnlyTheirOwnTransactions(string $kind, bool $caller, bool $missing, bool $throw): void
    {
        $state = $this->runNative(compact('kind', 'caller', 'missing', 'throw'));
        self::assertSame(!$missing && !$throw, (bool) $state['result'], json_encode(compact('kind', 'caller', 'missing', 'throw')));
        self::assertSame($throw, $state['failure']);
        self::assertSame($caller, $state['active']);
        self::assertSame($missing || ($throw && !$caller) ? 0 : 1, $state['inside']);
        self::assertSame($missing || $throw || $caller ? 0 : 1, $state['after']);
        self::assertSame($caller ? 'caller work' : 'owned device', $state['caller_work']);
        self::assertSame($caller ? ['begin' => 0, 'commit' => 0, 'rollback' => 0] : ['begin' => 1, 'commit' => $missing || $throw ? 0 : 1, 'rollback' => $missing || $throw ? 1 : 0], $state['calls']);
    }

    public function testReportDenialPrecedesLookupAndPreservesCallerTransaction(): void
    {
        foreach ([false, true] as $caller) {
            $state = $this->runNative(['kind' => 'report', 'caller' => $caller, 'missing' => false, 'throw' => false, 'authorized' => false]);
            self::assertFalse($state['result']);
            self::assertFalse($state['failure']);
            self::assertSame($caller, $state['active']);
            self::assertSame(['begin' => 0, 'commit' => 0, 'rollback' => 0], $state['calls']);
            self::assertSame(0, $state['inside']);
            self::assertSame(0, $state['after']);
            self::assertSame($caller ? 'caller work' : 'owned device', $state['caller_work']);
        }
    }

    /** @return array<string, array{string, bool, bool, bool}> */
    public static function outcomes(): array
    {
        $cases = [];
        foreach (['tree', 'report'] as $kind) {
            foreach ([false, true] as $caller) {
                foreach ([false, true] as $missing) {
                    $cases[$kind . ($caller ? ' caller transaction' : ' owned transaction') . ($missing ? ' missing destination' : ' success')] = [$kind, $caller, $missing, false];
                }
                $cases[$kind . ($caller ? ' caller transaction' : ' owned transaction') . ' failure after write'] = [$kind, $caller, false, true];
            }
        }
        return $cases;
    }

    /** @param array{kind: string, caller: bool, missing: bool, throw: bool, authorized?: bool} $scenario
     * @return array<string, mixed>
     */
    private function runNative(array $scenario): array
    {
        $process = proc_open([PHP_BINARY, '-d', 'error_reporting=' . error_reporting(), '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'zend.exception_ignore_args=1', dirname(__DIR__) . '/Fixtures/placement-transaction-native.php', json_encode($scenario, JSON_THROW_ON_ERROR)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Placement transaction fixture did not start');
        }
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        self::assertSame('', $error);
        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
}
