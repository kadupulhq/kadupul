<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Domain\GprintPreset;
use Kadupul\Graphing\Domain\GprintPresetFilters;
use Kadupul\Graphing\Domain\GprintPresetPage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Twig\TwigFunction;

final class GprintPresetSelectionLimitTest extends TestCase
{
    #[DataProvider('largePages')]
    public function testRenderedPageCannotSubmitAnOversizedSelection(int $count, string $locale): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'), ['strict_variables' => true]);
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', dirname(__DIR__, 2) . '/config/translations/gprint.' . $locale . '.yaml', $locale, 'gprint');
        $twig->addExtension(new TranslationExtension($translator));
        $twig->addFunction(new TwigFunction('path', static fn(string $route, array $parameters = []): string => '/' . $route . '?' . http_build_query($parameters)));
        $presets = array_map(static fn(int $id): GprintPreset => new GprintPreset($id, 'Preset ' . $id, '%5.2lf', 0, 0, (string) $id), range(1, $count));
        $filters = GprintPresetFilters::fromQuery(['rows' => '5000'], 25);
        $html = $twig->render('graphing/gprint_presets.html.twig', [
            'app' => ['request' => null], 'page' => new GprintPresetPage($presets, $count, $filters),
            'filters' => $filters->query(), 'defaultRows' => 25, 'saved' => false, 'deleted' => false,
        ]);
        $document = new \DOMDocument();
        self::assertTrue(@$document->loadHTML($html));
        $xpath = new \DOMXPath($document);
        self::assertSame(100, $xpath->query('//input[@name="ids[]" and not(@disabled)]')->length);
        self::assertSame($count - 100, $xpath->query('//input[@name="ids[]" and @disabled]')->length);
        self::assertStringContainsString($locale === 'fr' ? 'Sélectionnez au maximum 100 préréglages' : 'Select up to 100 presets', $document->textContent);
        self::assertStringContainsString($locale === 'fr' ? '100 lignes par page' : '100 rows per page', $document->textContent);
    }

    public static function largePages(): array
    {
        return [[101, 'en'], [5000, 'en'], [101, 'fr'], [5000, 'fr']];
    }
}
