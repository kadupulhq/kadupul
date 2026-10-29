<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests;

use InvalidArgumentException;
use Kadupul\Graphing\Domain\BoostGraphCacheKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BoostGraphCacheKeyTest extends TestCase
{
    /** @return array<string, string> */
    private static function viewer(array $override = []): array
    {
        return $override + [
            'theme' => 'modern', 'selected_theme' => 'modern', 'color_mode' => '', 'fonts' => str_repeat('a', 64),
            'date_format' => '1', 'datechar' => '1', 'php_tz' => 'UTC', 'tz' => '', 'lang' => '',
            'locale' => '', 'country' => '',
        ];
    }

    /** The key as lib/boost.php built it before the move. */
    private static function legacyKey(array $viewer, array $graph): string
    {
        $parts = ['theme' => $viewer['theme'], 'color_mode' => $viewer['color_mode'], 'fonts' => $viewer['fonts'],
            'date_format' => $viewer['date_format'], 'datechar' => $viewer['datechar'], 'php_tz' => $viewer['php_tz'],
            'tz' => $viewer['tz'], 'lang' => $viewer['lang'], 'locale' => $viewer['locale'], 'country' => $viewer['country'],
            'graph_start' => (string) ($graph['graph_start'] ?? ''), 'graph_end' => (string) ($graph['graph_end'] ?? '')];
        foreach (['graph_width', 'graph_height', 'graph_nolegend'] as $field) {
            $parts[$field] = [array_key_exists($field, $graph), $graph[$field] ?? null];
        }
        $parts['graphv'] = isset($graph['graphv']) ? 'on' : '';
        $parts['image_format'] = (string) ($graph['image_format'] ?? '');

        return hash('sha256', serialize($parts));
    }

    /** @return iterable<string, array{array<string,string>, array<string,mixed>}> */
    public static function cases(): iterable
    {
        yield 'defaults' => [self::viewer(), []];
        yield 'sized' => [self::viewer(), ['graph_width' => '600px', 'graph_height' => 150]];
        yield 'false nolegend' => [self::viewer(), ['graph_nolegend' => false]];
        yield 'window' => [self::viewer(['color_mode' => 'dark']), ['graph_start' => -3600, 'graph_end' => 1790510400, 'graphv' => true, 'image_format' => 'png']];
        yield 'locale and zone' => [self::viewer(['php_tz' => 'Asia/Tokyo', 'tz' => 'GMT-9', 'lang' => 'C', 'locale' => 'de-DE', 'country' => 'de']), []];
        yield 'non UTF-8 font fingerprint' => [self::viewer(['fonts' => "Sans\xff"]), []];
    }

    #[DataProvider('cases')]
    public function testMatchesLegacyKey(array $viewer, array $graph): void
    {
        $key = BoostGraphCacheKey::fromGraphRequest(7, 1, 0, $graph, $viewer);

        $this->assertSame(self::legacyKey($viewer, $graph), $key->renderKey());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key->renderKey());
    }

    public function testEveryViewerInputChangesTheKey(): void
    {
        $base = BoostGraphCacheKey::fromGraphRequest(7, 1, 0, [], self::viewer())->renderKey();
        foreach (['theme' => 'dark', 'color_mode' => 'dark', 'fonts' => 'b', 'date_format' => '3', 'datechar' => '0',
            'php_tz' => 'Europe/Berlin', 'tz' => 'GMT+3', 'lang' => 'fr', 'locale' => 'fr-FR', 'country' => 'fr'] as $name => $value) {
            $this->assertNotSame($base, BoostGraphCacheKey::fromGraphRequest(7, 1, 0, [], self::viewer([$name => $value]))->renderKey(), $name);
        }
    }

    public function testMissingViewerInputIsRejected(): void
    {
        $viewer = self::viewer();
        unset($viewer['locale']);

        $this->expectException(InvalidArgumentException::class);
        BoostGraphCacheKey::fromGraphRequest(7, 1, 0, [], $viewer);
    }

    #[DataProvider('negativeIds')]
    public function testRejectsNegativeIds(int $graph, int $rra, int $timespan): void
    {
        $this->expectException(InvalidArgumentException::class);
        BoostGraphCacheKey::fromGraphRequest($graph, $rra, $timespan, [], self::viewer());
    }

    /** @return iterable<string, array{int,int,int}> */
    public static function negativeIds(): iterable
    {
        yield 'graph' => [-1, 1, 0];
        yield 'rra' => [7, -1, 0];
        yield 'timespan' => [7, 0, -1];
    }

    public function testFileNameKeepsTheLegacyLayout(): void
    {
        $sized = BoostGraphCacheKey::fromGraphRequest(7, 1, 3600, ['graph_height' => '150px', 'graph_width' => '1/../x'], self::viewer());
        $this->assertSame('/c/modern_lgi_7_rrai_1_height_150_width_1_rk_' . $sized->renderKey() . '.png', $sized->fileName('/c'));

        $thumb = BoostGraphCacheKey::fromGraphRequest(7, 0, 3600, ['graph_nolegend' => false], self::viewer());
        $this->assertSame('/c/modern_lgi_7_rrai_0_tsi_3600_rk_' . $thumb->renderKey() . '_thumb.png', $thumb->fileName('/c'));
    }

    public function testFileNameStaysInsideCacheDirectory(): void
    {
        $key = BoostGraphCacheKey::fromGraphRequest(7, 1, 0, ['graph_height' => '../../etc', 'graph_width' => '/x'], self::viewer());
        $path = $key->fileName('/var/cache/boost');

        $this->assertSame('/var/cache/boost', dirname($path));
        $this->assertStringNotContainsString('..', basename($path));
        $this->assertStringNotContainsString('/', basename($path));
    }

    #[DataProvider('hostileThemes')]
    public function testFileNameRefusesAThemeThatCouldLeaveTheDirectory(string $theme): void
    {
        $key = BoostGraphCacheKey::fromGraphRequest(7, 1, 0, [], self::viewer(['selected_theme' => $theme]));

        $this->expectException(InvalidArgumentException::class);
        $key->fileName('/c');
    }

    /** @return iterable<string, array{string}> */
    public static function hostileThemes(): iterable
    {
        yield 'traversal' => ['../x'];
        yield 'separator' => ['a/b'];
        yield 'empty' => [''];
    }

    public function testFileNameRefusesAnEmptyOrNulDirectory(): void
    {
        $key = BoostGraphCacheKey::fromGraphRequest(7, 1, 0, [], self::viewer());
        foreach (['', "/c\0x"] as $directory) {
            try {
                $key->fileName($directory);
                $this->fail('accepted ' . json_encode($directory));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
