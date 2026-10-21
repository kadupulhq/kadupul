<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\DataSourceProfileSizing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

/** Gettext stand-in: the legacy helpers pass printf arguments after the message. */
function __(string $message, mixed ...$arguments): string
{
    return $arguments === [] ? $message : vsprintf($message, $arguments);
}

function number_format_i18n(float|int $number): string
{
    $formatter = new \NumberFormatter('en', \NumberFormatter::DECIMAL);
    $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 0);
    return (string) $formatter->format($number);
}

/** @param list<mixed> $parameters */
function db_fetch_cell_prepared(string $sql, array $parameters): mixed
{
    return str_contains($sql, 'SUM(') ? $GLOBALS['profile_sizing_rows'] : $GLOBALS['profile_sizing_cfs'];
}

$source = file_get_contents(dirname(__DIR__, 2) . '/data_source_profiles.php');
if (!is_string($source)) {
    throw new \RuntimeException('Unable to read the profile page');
}
// Runs the page's own helpers; the source is the checked-in file, never request data.
eval('namespace ' . __NAMESPACE__ . ';' . test_php_function_source($source, 'get_span') . test_php_function_source($source, 'get_size'));

/** Records the retention and RRDfile size text the profile form shows. */
final class DataSourceProfileSizingTest extends TestCase
{
    /** @return array<string, array{int, string}> */
    public static function spans(): array
    {
        return [
            'under an hour' => [3599, ''],
            'one hour' => [3600, '1 Hour'],
            'a day is still hours' => [86400, '24 Hours'],
            'just over a day' => [86401, '1 Day'],
            'day and hour' => [90000, '1 Day, 1 Hour'],
            'a week is still days' => [604800, '7 Days'],
            'thirty days' => [2592000, '4 Weeks, 2 Days'],
            'a year is still months' => [31536000, '12 Months, 5 Days'],
            'just over a year' => [31536001, '1 Year'],
            'default daily rra' => [86400 * 797, '2 Years, 2 Months, 7 Days'],
            'default hourly rra' => [7200 * 775, '2 Months, 4 Days, 14 Hours'],
            'largest row count' => [4294967295 * 604800, '82369235 Years, 9 Months, 2 Weeks, 6 Days'],
        ];
    }

    #[DataProvider('spans')]
    public function testSpanText(int $seconds, string $expected): void
    {
        self::assertSame($expected, get_span($seconds));
    }

    /** @return array<string, array{string, int|string, int, int|string|null, string}> */
    public static function sizes(): array
    {
        return [
            'profile with stored functions' => ['profile', '', 4, 3000, '96 KBytes per Data Sources and 284 Bytes for the Header'],
            'profile with checked functions' => ['profile', 2, 4, 3000, '48 KBytes per Data Sources and 284 Bytes for the Header'],
            'profile without archives' => ['profile', '', 4, null, '0 KBytes per Data Sources and 284 Bytes for the Header'],
            'large profile groups digits' => ['profile', '', 4, 600000, '19,200 KBytes per Data Sources and 284 Bytes for the Header'],
            'archive rows' => ['rra', 600, 4, '', '19 KBytes per Data Source'],
            'half a kilobyte rounds to even' => ['profile', 1, 4, 25, '0 KBytes per Data Sources and 284 Bytes for the Header'],
            'one and a half kilobytes rounds to even' => ['profile', 1, 4, 150, '2 KBytes per Data Sources and 284 Bytes for the Header'],
            'archive without rows' => ['rra', 0, 4, '', 'Enter a valid number of Rows to obtain the RRA size.'],
            'archive with negative rows' => ['rra', -5, 4, '', 'Enter a valid number of Rows to obtain the RRA size.'],
        ];
    }

    #[DataProvider('sizes')]
    public function testSizeText(string $type, int|string $countOrRows, int $storedFunctions, int|string|null $storedRows, string $expected): void
    {
        $GLOBALS['profile_sizing_cfs'] = (string) $storedFunctions;
        $GLOBALS['profile_sizing_rows'] = $storedRows;
        $text = $type === 'profile' ? get_size(7, 'profile', $countOrRows) : get_size(7, 'rra', '', $countOrRows);
        self::assertSame($expected, $text);
    }
}
