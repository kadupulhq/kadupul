<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Symfony;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Kadupul\LegacyWorker\pass_through_output;
use function Kadupul\LegacyWorker\read_json_command;

require_once __DIR__ . '/../../bin/worker-io.php';

final class LegacyWorkerIoTest extends TestCase
{
    /** @return resource */
    private static function stream(string $input)
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, $input);
        rewind($stream);

        return $stream;
    }

    /** A JSON object of exactly $bytes bytes. */
    private static function payload(int $bytes): string
    {
        $json = '{"a":""}';

        return '{"a":"' . str_repeat('x', $bytes - strlen($json)) . '"}';
    }

    public function testAPayloadAtTheCapIsDecoded(): void
    {
        $payload = self::payload(2000);

        self::assertSame(2000, strlen($payload));
        self::assertSame(['a' => str_repeat('x', 1992)], read_json_command(self::stream($payload), 2000, 4));
    }

    public function testAPayloadOneByteOverTheCapIsRefusedBeforeDecoding(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Payload too large');
        read_json_command(self::stream(self::payload(2001)), 2000, 4);
    }

    public function testTheWorkerChoosesTheOversizeException(): void
    {
        try {
            read_json_command(self::stream(self::payload(4097)), 4096, 8, \InvalidArgumentException::class, 'Invalid command');
            self::fail('An oversize payload was accepted');
        } catch (\InvalidArgumentException $refused) {
            self::assertSame(\InvalidArgumentException::class, $refused::class);
            self::assertSame('Invalid command', $refused->getMessage());
        }
    }

    public function testOnlyCapPlusOneBytesAreRead(): void
    {
        $stream = self::stream(str_repeat(' ', 10) . '{}');

        try {
            read_json_command($stream, 5, 4);
            self::fail('An oversize payload was accepted');
        } catch (\RuntimeException) {
            self::assertSame(6, ftell($stream));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidJson(): iterable
    {
        yield 'empty input' => [''];
        yield 'truncated object' => ['{'];
        yield 'not JSON' => ['actor=1'];
    }

    #[DataProvider('invalidJson')]
    public function testInputThatIsNotJsonThrowsJsonException(string $input): void
    {
        $this->expectException(\JsonException::class);
        read_json_command(self::stream($input), 1000, 4);
    }

    public function testNestingDeeperThanTheDepthIsRefused(): void
    {
        self::assertSame([[[1]]], read_json_command(self::stream('[[[1]]]'), 1000, 4));

        $this->expectException(\JsonException::class);
        $this->expectExceptionMessage('Maximum stack depth exceeded');
        read_json_command(self::stream('[[[[1]]]]'), 1000, 4);
    }

    public function testScalarsDecodeAsTheyDidInline(): void
    {
        self::assertSame([], read_json_command(self::stream('[]'), 20000, 4));
        self::assertNull(read_json_command(self::stream('null'), 20000, 4));
        self::assertSame(7, read_json_command(self::stream('7'), 20000, 4));
    }

    /** @return iterable<string, array{string, string}> */
    public static function legacyOutput(): iterable
    {
        yield 'no output' => ['', ''];
        yield 'output ending a line' => ["OK\n", "OK\n"];
        yield 'output without a final newline' => ['OK', 'OK' . PHP_EOL];
        yield 'only the end of the output counts' => ["a\nb", "a\nb" . PHP_EOL];
    }

    #[DataProvider('legacyOutput')]
    public function testTheMarkerAlwaysStartsALine(string $printed, string $before): void
    {
        ob_start();
        $finish = pass_through_output();
        echo $printed;
        echo '';
        $finish('KADUPUL_TEST_RESULT', ['status' => 'ok', 'reason' => "\xff"], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $output = ob_get_clean();

        self::assertSame($before . 'KADUPUL_TEST_RESULT={"status":"ok","reason":"\\ufffd"}' . PHP_EOL, $output);
    }
}
