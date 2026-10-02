<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Domain\PaletteColor;
use Kadupul\Graphing\Domain\PaletteColorFilters;
use Kadupul\Graphing\Domain\PaletteColorPage;
use Kadupul\Graphing\Infrastructure\Symfony\Form\PaletteColorDeletionType;
use Kadupul\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaletteColorRouteLinkTest extends TestCase
{
    public function testFixedRouteEncodesMaliciousFilterAndEscapesLabel(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $twig = $kernel->getContainer()->get('test.service_container')->get('twig');
            $payload = 'javascript:alert(1)" onclick="alert(2)<script>x</script>';
            $html = $twig->createTemplate('<a href="{{ path("palette_color_list", {filter: value}) }}">{{ value }}</a>')->render(['value' => $payload]);
            $document = new \DOMDocument();
            self::assertTrue(@$document->loadHTML($html));
            $link = $document->getElementsByTagName('a')->item(0);
            self::assertNotNull($link);
            self::assertStringStartsWith('/graphing/colors', $link->getAttribute('href'));
            self::assertFalse($link->hasAttribute('onclick'));
            self::assertSame(0, $document->getElementsByTagName('script')->length);
            parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
            self::assertSame($payload, $query['filter']);
        } finally {
            $kernel->shutdown();
        }
    }

    public static function displayNames(): iterable
    {
        yield 'empty' => ['', 'abcdef'];
        yield 'ASCII whitespace' => [" \t\r\n", 'abcdef'];
        yield 'Unicode spaces' => ["\u{00A0}\u{2003}\u{202F}", 'abcdef'];
        yield 'format characters' => ["\u{200B}\u{FEFF}\u{2060}", 'abcdef'];
        yield 'mixed invisible' => [" \u{00A0}\u{200B}", 'abcdef'];
        yield 'name equal to hex' => ['abcdef', 'abcdef'];
        yield 'visible exact name' => ["\u{00A0}Visible\u{200B} ", "\u{00A0}Visible\u{200B} "];
    }

    #[DataProvider('displayNames')]
    public function testActualTemplatesIdentifyColorsWithoutChangingStoredNames(string $name, string $expected): void
    {
        PaletteColor::validate($name, 'abcdef');
        $color = new PaletteColor(1, $name, 'abcdef', false);
        $revision = $color->revision;
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $twig = $container->get('twig');
            $filters = PaletteColorFilters::fromQuery(['named' => 'false'], 30, false);
            $html = $twig->render('graphing/palette_colors.html.twig', [
                'page' => new PaletteColorPage([$color], 1, $filters),
                'filters' => $filters->query(), 'defaultRows' => 30, 'saved' => false, 'deleted' => false,
            ]);
            $document = new \DOMDocument();
            self::assertTrue($document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING));
            $xpath = new \DOMXPath($document);
            $link = $xpath->query('//a[contains(@href,"/graphing/colors/1/edit")]')->item(0);
            $checkbox = $xpath->query('//input[@name="ids[]"]')->item(0);
            self::assertNotNull($link);
            self::assertNotNull($checkbox);
            self::assertSame($expected, $link->textContent);
            self::assertSame('Select ' . $expected, $checkbox->getAttribute('aria-label'));
            $form = $container->get('form.factory')->create(PaletteColorDeletionType::class, [
                'selection' => '[1]', 'revisions' => json_encode([1 => $revision], JSON_THROW_ON_ERROR),
            ], ['csrf_protection' => false]);
            $confirmation = $twig->render('graphing/palette_color_delete.html.twig', [
                'presets' => [$color], 'used' => !$color->isDeletable(), 'form' => $form->createView(),
            ]);
            self::assertTrue($document->loadHTML($confirmation, LIBXML_NOERROR | LIBXML_NOWARNING));
            $items = (new \DOMXPath($document))->query('//main/ul/li');
            self::assertCount(1, $items);
            self::assertSame($expected === $name ? 'abcdef · ' . $expected : 'abcdef', $items->item(0)->textContent);
            self::assertSame($name, $color->name);
            self::assertSame($revision, $color->revision);
        } finally {
            $kernel->shutdown();
        }
    }
}
