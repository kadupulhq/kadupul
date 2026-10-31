<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

use PHPUnit\Framework\TestCase;

final class SqlSaveOutcomeNativeTest extends TestCase
{
	protected function setUp(): void
	{
		if (!getenv('KADUPUL_TEST_MYSQL_DSN')) self::markTestSkipped('KADUPUL_TEST_MYSQL_DSN is required for database contracts');
	}

	/** @dataProvider outcomes */
	public function testTheReturnValueMatchesPersistenceAndPreservesCallerWork(string $mode, int $count, int $value, int|false $expected): void
	{
		$fixture = dirname(__DIR__, 2) . '/fixtures/sql-save-outcome-native.php';
		$process = proc_open([PHP_BINARY, '-d', 'zend.exception_ignore_args=1', $fixture, $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		self::assertIsResource($process);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		self::assertSame(0, proc_close($process), $stderr . $stdout);
		self::assertSame('', $stderr);
		$result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
		if ($expected === false) self::assertFalse($result['result']);
		else self::assertSame($expected, (int) $result['result']);
		self::assertTrue($result['caller_transaction']);
		self::assertCount($count, $result['rows']);
		self::assertSame(['id' => 1, 'value' => $value, 'label' => 'owned'], $result['rows'][0]);
		self::assertSame(41, (int) $result['default_unchanged']);
		if ($count === 2) self::assertSame(['id' => 2, 'value' => 43, 'label' => ''], $result['rows'][1]);
	}

	/** @return array<string, array{string, int, int, int|false}> */
	public static function outcomes(): array
	{
		return [
			'successful update' => ['update', 1, 43, 1],
			'unchanged update' => ['unchanged', 1, 42, 1],
			'rejected update' => ['rejected-update', 1, 42, false],
			'successful insert' => ['insert', 2, 42, 2],
			'rejected insert' => ['rejected-insert', 1, 42, false],
		];
	}
}
