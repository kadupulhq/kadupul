<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Legacy\LogText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LogTextTest extends TestCase
{
    private const string CONTROLS = '/[\x00-\x08\x0a-\x1f\x7f]|\xc2[\x80-\x9f]|\xd8\x9c|\xe2\x80[\x8e\x8f\xa8-\xae]|\xe2\x81[\xa6-\xa9]/';

    #[DataProvider('lineBreaks')]
    public function testCarriageReturnAndLineFeedBecomeSpaces(string $raw, string $expected): void
    {
        self::assertSame($expected, LogText::neutralize($raw));
    }

    /** @return iterable<string, array{string, string}> */
    public static function lineBreaks(): iterable
    {
        yield 'LF' => ["X\nY", 'X Y'];
        yield 'CR' => ["X\rY", 'X Y'];
        yield 'CRLF' => ["X\r\nY", 'X  Y'];
        yield 'LF beside another control' => ["X\n\x00Y", 'X \x00Y'];
    }

    public function testATabIsKept(): void
    {
        self::assertSame("a\tb", LogText::neutralize("a\tb"));
    }

    public function testAnEmptyStringStaysEmpty(): void
    {
        self::assertSame('', LogText::neutralize(''));
    }

    #[DataProvider('escapes')]
    public function testControlsAreWrittenAsVisibleEscapes(string $raw, string $escaped): void
    {
        self::assertSame('a' . $escaped . 'b', LogText::neutralize('a' . $raw . 'b'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function escapes(): iterable
    {
        foreach (array_merge(range(0x00, 0x08), range(0x0b, 0x0c), range(0x0e, 0x1f), [0x7f]) as $byte) {
            yield sprintf('C0 0x%02x', $byte) => [chr($byte), sprintf('\x%02x', $byte)];
        }
        foreach ([0x80, 0x85, 0x9b, 0x9f] as $codepoint) {
            yield sprintf('C1 U+%04X', $codepoint) => [mb_chr($codepoint, 'UTF-8'), sprintf('\u%04x', $codepoint)];
        }
        foreach ([0x061c, 0x200e, 0x200f, 0x2028, 0x2029, 0x202a, 0x202b, 0x202c, 0x202d, 0x202e, 0x2066, 0x2067, 0x2068, 0x2069] as $codepoint) {
            yield sprintf('U+%04X', $codepoint) => [mb_chr($codepoint, 'UTF-8'), sprintf('\u%04x', $codepoint)];
        }
    }

    #[DataProvider('unchanged')]
    public function testOtherTextIsKeptByteForByte(string $text): void
    {
        self::assertSame($text, LogText::neutralize($text));
    }

    /** @return iterable<string, array{string}> */
    public static function unchanged(): iterable
    {
        yield 'accents' => ['café naïve Ærø'];
        yield 'CJK' => ['日本語のログ'];
        yield 'emoji' => ["\u{1F600} \u{1F468}\u{200D}\u{1F469}"];
        yield 'Arabic and Hebrew' => ['مرحبا שלום'];
        // Neighbours of the escaped ranges must not match.
        yield 'U+00A0 and U+2027' => ["\u{00A0}\u{2027}\u{202F}\u{2065}\u{206A}\u{061B}"];
        yield 'invalid UTF-8' => ["a\xff\xfe\xc3b\x9b\xe2\x80"];
    }

    public function testInvalidUtf8BesideAControlIsNotDropped(): void
    {
        self::assertSame('\x00' . "\xff\xc3" . ' ' . "\x9b", LogText::neutralize("\x00\xff\xc3\n\x9b"));
    }

    public function testAPcreFailureStillReplacesCarriageReturnAndLineFeed(): void
    {
        $file = dirname(__DIR__, 2) . '/src/Platform/Infrastructure/Legacy/LogText.php';
        $code = 'require ' . var_export($file, true) . ';'
            . '$text = "' . str_repeat('a', 64) . '\x00X\nY\rZ";'
            . 'echo json_encode([preg_match(' . var_export(self::CONTROLS, true) . ', $text), base64_encode(\Kadupul\Platform\Infrastructure\Legacy\LogText::neutralize($text))]);';
        $out = shell_exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, '-n', '-d', 'pcre.jit=0', '-d', 'pcre.backtrack_limit=1', '-r', $code])));
        self::assertIsString($out);
        [$probe, $neutralized] = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        // The probe shows the child really reaches the PCRE failure path.
        self::assertFalse($probe);
        self::assertSame(str_repeat('a', 64) . "\x00X Y Z", base64_decode($neutralized, true));
    }
}
