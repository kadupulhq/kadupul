<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

final class PluginDdlCharsetDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        if (!getenv('KADUPUL_TEST_MYSQL_DSN')) {
            self::markTestSkipped('KADUPUL_TEST_MYSQL_DSN is required for database contracts');
        }
    }

    public function testCoreCreatesColumnsUsingTheRequestedCharacterSet(): void
    {
        $this->assertNativeCharset('core', 0);
    }

    public function testPluginCreatesColumnsUsingTheRequestedCharacterSet(): void
    {
        $this->assertNativeCharset('plugin', 1);
    }

    private function assertNativeCharset(string $mode, int $journalRows): void
    {
        $fixture = dirname(__DIR__) . '/Fixtures/plugin-ddl-charset-native.php';
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'zend.exception_ignore_args=1', $fixture, $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr . $stdout);
        self::assertSame('', $stderr);
        $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['CHARACTER_SET_NAME' => 'utf8mb4', 'COLLATION_NAME' => 'utf8mb4_unicode_ci'], $result['column']);
        self::assertNull($result['insert_error']);
        self::assertSame('F09F9880', $result['round_trip']);
        self::assertSame($journalRows, $result['journal_rows']);
    }
}
