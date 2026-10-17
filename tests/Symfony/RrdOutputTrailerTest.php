<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class RrdOutputTrailerTest extends TestCase
{
    #[DataProvider('responses')]
    public function testOnlyTheProtocolTrailerIsRemoved(string $os, string $response, string $expected): void
    {
        // Load the complete production library in isolation, without a database
        // or application bootstrap. Base64 keeps binary output intact in transit.
        $program = '$config = ' . var_export(['cacti_server_os' => $os], true) . ';'
            . 'require ' . var_export(dirname(__DIR__, 2) . '/lib/rrd.php', true) . ';'
            . '$output = base64_decode(' . var_export(base64_encode($response), true) . ');'
            . 'rrdtool_trim_output($output); echo base64_encode($output);';
        $process = new Process([PHP_BINARY, '-r', $program]);
        $process->setTimeout(5);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('', $process->getErrorOutput());
        self::assertSame(base64_encode($expected), $process->getOutput());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function responses(): iterable
    {
        $payloads = [
            'empty' => '',
            'O' => 'PNGDATAO',
            'K' => 'PNGDATAK',
            'space' => 'PNGDATA ',
            'CR' => "PNGDATA\r",
            'LF' => "PNGDATA\n",
            'CRLF' => "PNGDATA\r\n",
            'binary' => "\x89PNG\r\n\x1a\n\x00\xffOK \r\n",
            'earlier status' => "first\nOK u:0.01\nsecond K",
        ];
        $trailers = [
            'bare LF' => "OK\n",
            'bare CRLF' => "OK\r\n",
            'timed LF' => "OK u:0.01 s:0.02 r:0.03\n",
            'timed CRLF' => "OK u:0.01 s:0.02 r:0.03\r\n",
            'user time only' => "OK u:0.01\n",
        ];
        foreach ($payloads as $name => $payload) {
            foreach ($trailers as $format => $trailer) {
                yield $name . ', ' . $format => ['win32', $payload . $trailer, $payload];
            }
        }
        foreach (['', 'PNGDATAK', 'PNGDATAO ', "payload\r\n", "ERROR: broken\n", 'payloadOK', 'payloadOK u:0.01', "payloadOK\nextra", "payloadOK\n\n", "payloadOK u:0.01\nextra"] as $index => $response) {
            yield 'no final complete trailer ' . $index => ['win32', $response, $response];
        }

        yield 'Unix timed trailer' => ['unix', "payload K\r\nOK u:0.01 s:0.02 r:0.03\n", "payload K\r\n"];
        yield 'Unix bare response unchanged' => ['unix', "payload K\r\nOK\n", "payload K\r\nOK\n"];
        yield 'Unix payload unchanged' => ['unix', "payload K\r\n", "payload K\r\n"];
    }
}
