<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once __DIR__ . '/NativeSessionChildCoverage.php';

final class NativeSessionDataTest extends TestCase
{
    public static function nativeCases(): iterable
    {
        foreach (['php', 'php_binary', 'php_serialize'] as $serializer) {
            foreach (['note', 'array', 'reference', 'objects', 'opaque', 'opaque-reference', 'nested', 'large', 'plugin-state'] as $scenario) {
                yield $serializer . '/' . $scenario => [$serializer, $scenario];
            }
        }
    }

    #[DataProvider('nativeCases')]
    public function testNativeSerializerOracle(string $serializer, string $scenario): void
    {
        $coverage = NativeSessionChildCoverage::start('codec:' . $serializer . ':' . $scenario, 'session-data-native.php');
        try {
            $process = new Process([PHP_BINARY, ...($coverage?->arguments() ?? []), dirname(__DIR__) . '/Fixtures/session-data-native.php', $serializer, $scenario], env: $coverage?->environment());
            $process->mustRun();
            self::assertSame('', $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($result);
            self::assertSame(0, $result['parser_awoken']);
            if ($scenario === 'opaque-reference') {
                self::assertTrue($result['refused']);
                $coverage?->finish();
                return;
            }
            self::assertTrue($result['matches']);
            self::assertTrue($result['decoded']);
            self::assertSame(2000, $result['decoded_activity']);
            self::assertTrue($result['note_preserved']);
            $coverage?->finish();
        } finally {
            $coverage?->cleanup();
        }
    }
}
