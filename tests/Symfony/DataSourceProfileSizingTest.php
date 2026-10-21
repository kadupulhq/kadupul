<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Domain\DataSourceProfileSizing;
use Kadupul\Graphing\Infrastructure\Symfony\DataSourceProfileText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * The retention and RRDfile size text the profile form shows. The expected
 * strings were recorded from the legacy get_span() and get_size() before the
 * page moved to Symfony (commit "test(profiles): record the profile size and
 * retention text").
 */
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
        self::assertSame($expected, self::text('en')->span($seconds));
    }

    /** @return array<string, array{string, int, int, string}> */
    public static function sizes(): array
    {
        return [
            'profile with stored functions' => ['profile', 3000, 4, '96 KBytes per Data Sources and 284 Bytes for the Header'],
            'profile with checked functions' => ['profile', 3000, 2, '48 KBytes per Data Sources and 284 Bytes for the Header'],
            'profile without archives' => ['profile', 0, 4, '0 KBytes per Data Sources and 284 Bytes for the Header'],
            'large profile groups digits' => ['profile', 600000, 4, '19,200 KBytes per Data Sources and 284 Bytes for the Header'],
            'archive rows' => ['rra', 600, 4, '19 KBytes per Data Source'],
            'half a kilobyte rounds to even' => ['profile', 25, 1, '0 KBytes per Data Sources and 284 Bytes for the Header'],
            'one and a half kilobytes rounds to even' => ['profile', 150, 1, '2 KBytes per Data Sources and 284 Bytes for the Header'],
            'archive without rows' => ['rra', 0, 4, 'Enter a valid number of Rows to obtain the RRA size.'],
            'archive with negative rows' => ['rra', -5, 4, 'Enter a valid number of Rows to obtain the RRA size.'],
        ];
    }

    #[DataProvider('sizes')]
    public function testSizeText(string $type, int $rows, int $functions, string $expected): void
    {
        $text = self::text('en');
        self::assertSame($expected, $type === 'profile' ? $text->profileSize($rows, $functions) : $text->archiveSize($rows, $functions));
    }

    public function testFrenchUsesTheRequestLocaleForWordsAndDigits(): void
    {
        $text = self::text('fr');
        self::assertSame('2 ans, 2 mois, 7 jours', $text->span(86400 * 797));
        self::assertSame("19\u{202F}200 Ko par source de données et 284 octets pour l'en-tête", $text->profileSize(600000, 4));
    }

    /** @return array<string, array{string, int, string}> */
    public static function labels(): array
    {
        return [
            'interval seconds' => ['interval', 30, 'Every 30 Seconds'],
            'interval minute' => ['interval', 60, 'Every Minute'],
            'interval minutes' => ['interval', 300, 'Every 5 Minutes'],
            'interval hour' => ['interval', 3600, 'Every Hour'],
            'interval hours' => ['interval', 43200, 'Every 12 Hours'],
            'interval day' => ['interval', 86400, 'Every 1 Day'],
            'heartbeat seconds' => ['heartbeat', 90, '90 Seconds'],
            'heartbeat half minutes' => ['heartbeat', 330, '5.5 Minutes'],
            'heartbeat hour' => ['heartbeat', 3600, '1 Hour'],
            'heartbeat days' => ['heartbeat', 172800, '2 Days'],
            'level per sample' => ['level', 1, 'Selected Poller Interval'],
            'level minute' => ['level', 60, '1 Minute'],
            'level hours' => ['level', 21600, '6 Hours'],
            'level week' => ['level', 604800, '1 Week'],
            'timespan weeks' => ['timespan', 1814400, '3 Weeks'],
            'timespan month' => ['timespan', 2618784, '1 Month'],
            'timespan months' => ['timespan', 15712704, '6 Months'],
            'timespan years' => ['timespan', 315360000, '10 Years'],
            'timespan off the list' => ['timespan', 90000, '1 Day, 1 Hour'],
        ];
    }

    #[DataProvider('labels')]
    public function testChoiceLabelsMatchTheLegacyLists(string $kind, int $seconds, string $expected): void
    {
        self::assertSame($expected, self::text('en')->{$kind}($seconds));
    }

    public function testRetentionOverflowIsUnknownRatherThanAFloat(): void
    {
        self::assertSame(300 * 600, DataSourceProfileSizing::retention(300, 1, 600));
        self::assertNull(DataSourceProfileSizing::retention(4294967295, 4294967295, 4294967295));
        self::assertSame('N/A', self::text('en')->retention(4294967295, 4294967295, 4294967295));
    }

    private static function text(string $locale): DataSourceProfileText
    {
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', dirname(__DIR__, 2) . '/config/translations/data_source_profile.' . $locale . '.yaml', $locale, 'data_source_profile');
        $requests = new RequestStack();
        $request = new Request();
        $request->setLocale($locale);
        $requests->push($request);
        return new DataSourceProfileText($translator, $requests);
    }
}
