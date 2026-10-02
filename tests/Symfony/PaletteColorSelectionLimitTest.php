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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Twig\TwigFunction;

final class PaletteColorSelectionLimitTest extends TestCase
{
    #[DataProvider('largePages')]
    public function testRenderedPageCannotSubmitAnOversizedSelection(int $count, string $locale): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'), ['strict_variables' => true]);
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', dirname(__DIR__, 2) . '/config/translations/palette.' . $locale . '.yaml', $locale, 'palette');
        $twig->addExtension(new TranslationExtension($translator));
        $twig->addFunction(new TwigFunction('path', static fn(string $route, array $parameters = []): string => '/' . $route . '?' . http_build_query($parameters)));
        $presets = array_map(static fn(int $id): PaletteColor => new PaletteColor($id, 'Color ' . $id, 'abc', false), range(1, $count));
        $filters = PaletteColorFilters::fromQuery(['rows' => '5000'], 25);
        $html = $twig->render('graphing/palette_colors.html.twig', [
            'app' => ['request' => null], 'page' => new PaletteColorPage($presets, $count, $filters),
            'filters' => $filters->query(), 'defaultRows' => 25, 'saved' => false, 'deleted' => false,
        ]);
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
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'), ['strict_variables' => true]);
        $twig->addFilter(new \Twig\TwigFilter('trans', static fn(string $value, array $parameters = []): string => strtr($value, $parameters)));
        $twig->addFunction(new TwigFunction('path', static fn(string $route, array $parameters = []): string => '/' . $route));
        $filters = PaletteColorFilters::fromQuery(['rows' => '5000'], 25);
        $colors = array_map(static fn(int $id): PaletteColor => new PaletteColor($id, 'Color ' . $id, 'abc', $id === 1, $id === 2 ? 1 : 0), range(1, 101));
        $html = $twig->render('graphing/palette_colors.html.twig', ['app' => ['request' => null], 'page' => new PaletteColorPage($colors, 101, $filters), 'filters' => $filters->query(), 'defaultRows' => 25, 'deleted' => false]);
        $document = new \DOMDocument();
        self::assertTrue(@$document->loadHTML($html));
        $xpath = new \DOMXPath($document);
        self::assertSame(98, $xpath->query('//input[@name="ids[]" and not(@disabled)]')->length);
        foreach ([1, 2, 101] as $id) {
            self::assertSame(1, $xpath->query('//input[@name="ids[]" and @value="' . $id . '" and @disabled]')->length);
        }
    }

    public static function largePages(): array
    {
        return [[101, 'en'], [5000, 'en'], [101, 'fr'], [5000, 'fr']];
    }
}
