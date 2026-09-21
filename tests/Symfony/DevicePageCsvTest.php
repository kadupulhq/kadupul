<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Application\ReadModel\DevicePage;
use Kadupul\Inventory\Application\ReadModel\DeviceSummary;
use Kadupul\Inventory\Infrastructure\Symfony\Export\DevicePageCsv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DevicePageCsvTest extends TestCase
{
    #[DataProvider('spreadsheetText')]
    public function testTextRoundTripsAsLiteralCells(string $text): void
    {
        $csv = (new DevicePageCsv())->encode(new DevicePage([
            new DeviceSummary(42, $text, $text, false, 'Up'),
            new DeviceSummary(43, 'disabled', 'router.invalid', true, 'Up'),
        ], true));
        self::assertStringStartsWith("\xEF\xBB\xBFID,Name,Hostname,Status\r\n", $csv);
        $stream = new \SplTempFileObject();
        $stream->fwrite(substr($csv, 3));
        $stream->rewind();
        self::assertSame(['ID', 'Name', 'Hostname', 'Status'], $stream->fgetcsv(',', '"', ''));
        self::assertSame(['42', "'" . $text, "'" . $text, 'Up'], $stream->fgetcsv(',', '"', ''));
        self::assertSame(['43', "'disabled", "'router.invalid", 'Disabled'], $stream->fgetcsv(',', '"', ''));
        self::assertFalse($stream->fgetcsv(',', '"', ''));
    }

    public static function spreadsheetText(): iterable
    {
        foreach (['', '東京 router', 'comma,quote"backslash\\', "multi\r\nline", '=1+1', '+1+1', '-1+1', '@SUM(1,2)', " \t=1+1", "\r=1+1", "\n=1+1", "'literal"] as $text) {
            yield [$text];
        }
    }

    public function testEmptyPageStillHasColumnHeaders(): void
    {
        self::assertSame("\xEF\xBB\xBFID,Name,Hostname,Status\r\n", (new DevicePageCsv())->encode(new DevicePage([], false)));
    }
}
