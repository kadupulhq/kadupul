<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\GraphDefinition\Domain\VdefListCriteria;
use Kadupul\Graphing\Domain\PaletteColor;
use Kadupul\Graphing\Domain\PaletteColorFilters;
use Kadupul\Graphing\Domain\PaletteColorPage;
use Kadupul\IdentityAccess\Contract\AuthenticatedAccess;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\DatabaseConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The list macros must not change what the palette and VDEF lists submit or
 * link to. Each case is compared with HTML captured from the templates before
 * they used templates/_list.html.twig. Class attributes are dropped before the
 * comparison because the macros add the legacy theme hooks.
 */
final class ListTemplateRenderingTest extends TestCase
{
    private const string GOLDEN_DIR = __DIR__ . '/Fixtures/list-rendering';

    #[DataProvider('cases')]
    public function testListRegionMatchesThePreMacroRendering(string $name, string $template, \Closure $context): void
    {
        $actual = self::listRegion(self::render($template, $context()));
        self::assertStringEqualsFile(self::GOLDEN_DIR . '/' . $name . '.html', $actual);
    }

    public function testListMarkupCarriesLegacyThemeHooks(): void
    {
        $context = self::cases()['palette sorted by hex descending, page 2'][2];
        $document = new \DOMDocument();
        self::assertTrue($document->loadHTML(self::render('graphing/palette_colors.html.twig', $context()), LIBXML_NOERROR | LIBXML_NOWARNING));
        $xpath = new \DOMXPath($document);
        self::assertSame(1, $xpath->query('//form[contains(concat(" ", @class, " "), " filterTable ")]')->length);
        self::assertSame(1, $xpath->query('//table[contains(concat(" ", @class, " "), " cactiTable ")]')->length);
        self::assertSame(1, $xpath->query('//thead/tr[contains(concat(" ", @class, " "), " tableHeader ")]')->length);
        self::assertSame(4, $xpath->query('//th[contains(concat(" ", @class, " "), " sortable ")]')->length);
        self::assertSame(1, $xpath->query('//th[contains(concat(" ", @class, " "), " primarySort ")]//a[contains(@href, "sort_column=hex")]')->length);
        self::assertSame(1, $xpath->query('//nav[contains(concat(" ", @class, " "), " navBarNavigation ")]')->length);
        self::assertSame(1, $xpath->query('//nav//*[contains(concat(" ", @class, " "), " navBarNavigationPrevious ")]')->length);
        self::assertSame(2, $xpath->query('//td[contains(concat(" ", @class, " "), " checkbox ")]/input[@name="ids[]"]')->length);
    }

    public function testMacrosEscapeValuesAndTranslateInTheCallersDomain(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $container->get('translator')->setLocale('fr');
            $hostile = '"><script>x</script>';
            $html = $container->get('twig')->createTemplate(<<<'TWIG'
                {% import '_list.html.twig' as list %}
                <table><tr>{{ list.sort_header('graph_vdefs', {filter: value, sort_column: 'name', sort_direction: 'ASC'}, 'name', 'Next', 'graph_definition') }}</tr>
                <tr>{{ list.selection_cell(value, value, 'palette', 'row-' ~ value, true) }}</tr></table>
                {{ list.pagination('graph_vdefs', {filter: value}, 2, true, true, 'VDEF pages', 'Page %page% of %pages%', {'%page%': 2, '%pages%': 3}, 'graph_definition') }}
                TWIG)->render(['value' => $hostile]);
        } finally {
            $kernel->shutdown();
        }
        $document = new \DOMDocument();
        self::assertTrue($document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING));
        $xpath = new \DOMXPath($document);

        self::assertSame(0, $xpath->query('//script')->length);
        $sort = $xpath->query('//th[@class="sortable primarySort"]/a')->item(0);
        self::assertSame('Suivant', $sort->textContent);
        parse_str((string) parse_url($sort->getAttribute('href'), PHP_URL_QUERY), $query);
        self::assertSame(['filter' => $hostile, 'sort_column' => 'name', 'sort_direction' => 'DESC'], $query);
        $checkbox = $xpath->query('//td[@class="checkbox"]/input')->item(0);
        self::assertSame($hostile, $checkbox->getAttribute('value'));
        self::assertSame('Sélectionner ' . $hostile, $checkbox->getAttribute('aria-label'));
        self::assertTrue($checkbox->hasAttribute('disabled'));
        $label = $xpath->query('//td[@class="checkbox"]/label')->item(0);
        self::assertSame('Sélectionner', $label->textContent);
        self::assertSame('row-' . $hostile, $label->getAttribute('for'));
        self::assertSame('row-' . $hostile, $checkbox->getAttribute('id'));
        self::assertSame(['Précédent', 'Page 2 sur 3', 'Suivant'], array_map(static fn(\DOMNode $node): string => $node->textContent, iterator_to_array($xpath->query('//nav[@class="navBarNavigation"]/*'))));
        foreach (['prev' => '1', 'next' => '3'] as $rel => $target) {
            parse_str((string) parse_url($xpath->query('//a[@rel="' . $rel . '"]')->item(0)->getAttribute('href'), PHP_URL_QUERY), $query);
            self::assertSame(['filter' => $hostile, 'page' => $target], $query);
        }
    }

    /** @return array<string, array{string, string, \Closure(): array<string, mixed>}> */
    public static function cases(): array
    {
        $palette = static function (array $query, array $colors, int $total, bool $deleted = false): \Closure {
            return static function () use ($query, $colors, $total, $deleted): array {
                $filters = PaletteColorFilters::fromQuery($query, 30);

                return [
                    'page' => new PaletteColorPage($colors, $total, $filters),
                    'filters' => $filters->query(), 'defaultRows' => 30, 'saved' => false, 'deleted' => $deleted,
                ];
            };
        };
        $vdefs = static function (VdefListCriteria $criteria, array $rows, int $page, int $pages): \Closure {
            return static fn(): array => ['rows' => $rows, 'total' => count($rows), 'page' => $page, 'pages' => $pages, 'criteria' => $criteria];
        };
        $hostile = 'x" onmouseover="alert(1)<script>';

        return [
            'palette default' => ['palette-default', 'graphing/palette_colors.html.twig', $palette(
                [],
                [new PaletteColor(1, 'Red', 'ff0000', false), new PaletteColor(2, '', '00ff00', true), new PaletteColor(3, 'Used', '0000ff', false, 2)],
                3,
            )],
            'palette sorted by hex descending, page 2' => ['palette-sorted-page-2', 'graphing/palette_colors.html.twig', $palette(
                ['filter' => $hostile, 'rows' => '2', 'page' => '2', 'sort_column' => 'hex', 'sort_direction' => 'DESC', 'has_graphs' => 'true', 'named' => 'false'],
                [new PaletteColor(4, $hostile, 'abcdef', false), new PaletteColor(5, 'Blue', '0000ff', false)],
                7,
                true,
            )],
            'palette empty' => ['palette-empty', 'graphing/palette_colors.html.twig', $palette(['sort_column' => 'graphs', 'sort_direction' => 'ASC'], [], 0)],
            'vdefs first page' => ['vdefs-first-page', 'graph_definition/vdefs.html.twig', $vdefs(
                new VdefListCriteria($hostile, 1, 50, 'graphs', 'desc', true),
                [['id' => 1, 'name' => 'Maximum', 'inUse' => true, 'graphs' => 3, 'templates' => 1, 'referencingVdefs' => 0],
                    ['id' => 2, 'name' => $hostile, 'inUse' => false, 'graphs' => 0, 'templates' => 0, 'referencingVdefs' => 0]],
                1,
                3,
            )],
            'vdefs middle page' => ['vdefs-middle-page', 'graph_definition/vdefs.html.twig', $vdefs(
                new VdefListCriteria('', 2, 30, 'name', 'asc', false),
                [['id' => 9, 'name' => 'Average', 'inUse' => false, 'graphs' => 0, 'templates' => 2, 'referencingVdefs' => 1]],
                2,
                3,
            )],
            'vdefs empty' => ['vdefs-empty', 'graph_definition/vdefs.html.twig', $vdefs(new VdefListCriteria(), [], 1, 1)],
        ];
    }

    /** @param array<string, mixed> $context */
    public static function render(string $template, array $context): string
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $container->set(ConsoleAccess::class, new class implements ConsoleAccess {
                public function consoleActor(): ?\Kadupul\IdentityAccess\Contract\Actor
                {
                    return null;
                }

                public function canManageDevices(\Kadupul\IdentityAccess\Contract\Actor $actor): bool
                {
                    return false;
                }
            });
            $container->set(AuthenticatedAccess::class, new class implements AuthenticatedAccess {
                public function authenticatedActor(): ?\Kadupul\IdentityAccess\Contract\Actor
                {
                    return null;
                }
            });
            $pdo = new \PDO('sqlite::memory:');
            $container->set(DatabaseConnection::class, new class ($pdo) implements DatabaseConnection {
                public function __construct(private \PDO $pdo) {}

                public function get(): \PDO
                {
                    return $this->pdo;
                }
            });

            return $container->get('twig')->render($template, $context);
        } finally {
            $kernel->shutdown();
        }
    }

    /** The <main> element with class attributes removed and whitespace between tags collapsed. */
    public static function listRegion(string $html): string
    {
        $document = new \DOMDocument();
        self::assertTrue($document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING));
        $main = $document->getElementsByTagName('main')->item(0);
        self::assertNotNull($main);
        foreach ((new \DOMXPath($document))->query('.//*[@class]', $main) as $element) {
            $element->removeAttribute('class');
        }
        $markup = (string) $document->saveHTML($main);
        $markup = preg_replace('/>\s+</', '><', $markup);

        return trim((string) preg_replace('/\s+/', ' ', $markup)) . "\n";
    }
}
