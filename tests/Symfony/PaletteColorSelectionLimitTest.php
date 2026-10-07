<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Domain\PaletteColor;
use Kadupul\Graphing\Domain\PaletteColorFilters;
use Kadupul\Graphing\Domain\PaletteColorPage;
use Kadupul\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaletteColorSelectionLimitTest extends TestCase
{
    #[DataProvider('largePages')]
    public function testRenderedPageCannotSubmitAnOversizedSelection(int $count, string $locale): void
    {
        $presets = array_map(static fn(int $id): PaletteColor => new PaletteColor($id, 'Color ' . $id, 'abc', false), range(1, $count));
        $filters = PaletteColorFilters::fromQuery(['rows' => '5000'], 25);
        $html = self::render([
            'page' => new PaletteColorPage($presets, $count, $filters),
            'filters' => $filters->query(), 'defaultRows' => 25, 'saved' => false, 'deleted' => false,
        ], $locale);
        $document = new \DOMDocument();
        self::assertTrue(@$document->loadHTML($html));
        $xpath = new \DOMXPath($document);
        self::assertSame(100, $xpath->query('//input[@name="ids[]" and not(@disabled)]')->length);
        self::assertSame($count - 100, $xpath->query('//input[@name="ids[]" and @disabled]')->length);
        self::assertStringContainsString($locale === 'fr' ? 'Sélectionnez au maximum 100 couleurs' : 'Select up to 100 colors', $document->textContent);
        self::assertStringContainsString($locale === 'fr' ? '100 lignes par page' : '100 rows per page', $document->textContent);
        self::assertSame(1, $xpath->query('//form[@aria-describedby="palette-selection-help"]')->length);
        self::assertSame($count, $xpath->query('//tbody/tr')->length);
    }

    public function testProtectedRowsRemainDisabledWithinTheSharedSelectionLimit(): void
    {
        $filters = PaletteColorFilters::fromQuery(['rows' => '5000'], 25);
        $colors = array_map(static fn(int $id): PaletteColor => new PaletteColor($id, 'Color ' . $id, 'abc', $id === 1, $id === 2 ? 1 : 0), range(1, 101));
        $html = self::render(['page' => new PaletteColorPage($colors, 101, $filters), 'filters' => $filters->query(), 'defaultRows' => 25, 'deleted' => false], 'en');
        $document = new \DOMDocument();
        self::assertTrue(@$document->loadHTML($html));
        $xpath = new \DOMXPath($document);
        self::assertSame(98, $xpath->query('//input[@name="ids[]" and not(@disabled)]')->length);
        foreach ([1, 2, 101] as $id) {
            self::assertSame(1, $xpath->query('//input[@name="ids[]" and @value="' . $id . '" and @disabled]')->length);
        }
    }

    /** @param array<string, mixed> $context */
    private static function render(array $context, string $locale): string
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $container->get('translator')->setLocale($locale);

            return $container->get('twig')->render('graphing/palette_colors.html.twig', $context);
        } finally {
            $kernel->shutdown();
        }
    }

    public static function largePages(): array
    {
        return [[101, 'en'], [5000, 'en'], [101, 'fr'], [5000, 'fr']];
    }
}
