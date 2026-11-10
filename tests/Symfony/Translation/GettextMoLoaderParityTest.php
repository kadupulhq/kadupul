<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Translation;

use Kadupul\Kernel;
use Kadupul\Platform\Infrastructure\Symfony\GettextMoLoader;
use Kadupul\Platform\Infrastructure\Symfony\PluralFormsExpression;
use Kadupul\Platform\Infrastructure\Symfony\TranslationResources;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * The cacti domain must answer every legacy lookup exactly as php-gettext
 * does. The legacy helpers render the rendered corpus twice: over php-gettext
 * (the oracle) and over Symfony catalogues built by TranslationResources and
 * GettextMoLoader, first compiling the catalogue cache and then reading it.
 */
final class GettextMoLoaderParityTest extends TestCase
{
    private static ?string $cache = null;

    private static ?RenderedCorpus $warm = null;

    private static ?Translator $translator = null;

    public static function tearDownAfterClass(): void
    {
        RenderedCorpus::release();
        self::$warm = null;
        self::$translator = null;
        if (self::$cache !== null) {
            self::remove(self::$cache);
            self::$cache = null;
        }
    }

    /**
     * The corpus rendered over Symfony catalogues read from a warm cache. The
     * cold pass that compiles the cache is compared with php-gettext first.
     */
    private static function symfony(): RenderedCorpus
    {
        if (self::$warm === null) {
            self::$cache = sys_get_temp_dir() . '/kadupul-cacti-catalogue-' . bin2hex(random_bytes(8));
            mkdir(self::$cache, 0700);
            $root = RenderedCorpus::root();
            $gettext = RenderedCorpus::shared();
            self::assertSameRendering($gettext, RenderedCorpus::render($root, RenderedCorpus::locales($root), $gettext->calls, self::$cache), 'cold');
            // One compiled catalogue per shipped locale, written by the cold pass.
            self::assertSame(23, count(glob(self::$cache . '/catalogue.*.php') ?: []));
            self::$warm = RenderedCorpus::render($root, RenderedCorpus::locales($root), $gettext->calls, self::$cache);
        }

        return self::$warm;
    }

    public function testEveryLocaleRendersTheLegacyCorpusFromTheCactiDomain(): void
    {
        self::assertSameRendering(RenderedCorpus::shared(), self::symfony(), 'warm');
    }

    private static function assertSameRendering(RenderedCorpus $gettext, RenderedCorpus $symfony, string $pass): void
    {
        // Booleans and counts, not arrays: PHPUnit exports every asserted value, even on success.
        self::assertTrue($gettext->calls === $symfony->calls);
        self::assertSame(array_keys($gettext->renderings), array_keys($symfony->renderings));
        foreach ($gettext->renderings as $locale => $expected) {
            $actual = $symfony->renderings[$locale];
            // The comparison is only meaningful if every domain php-gettext
            // loaded was really answered by the Symfony catalogue.
            self::assertSame(array_fill_keys(array_keys($expected['domains']), 'gettext_reader'), $expected['readers'], $pass . ' ' . $locale);
            self::assertSame(array_fill_keys(array_keys($expected['domains']), CatalogueGettextReader::class), $actual['readers'], $pass . ' ' . $locale);
            $differing = 0;
            $examples = [];
            foreach ($gettext->calls as $index => $call) {
                $before = [$expected['values'][$index], $expected['errors'][$index] ?? null];
                $after = [$actual['values'][$index], $actual['errors'][$index] ?? null];
                if ($before !== $after && $differing++ < 5) {
                    $examples[] = json_encode([$call, $before, $after], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                }
            }
            self::assertSame(0, $differing, $pass . ' ' . $locale . ' differs from php-gettext, for example ' . implode("\n", $examples));
            $gettextIndex = array_map(static fn(array $indexes): array => array_map('intval', $indexes), $expected['plural_index']);
            self::assertTrue($gettextIndex === $actual['plural_index'], $pass . ' ' . $locale . ' plural index');
        }
    }

    public function testPluralIndexAgreesForEveryShippedHeaderFromZeroToAThousand(): void
    {
        $gettext = RenderedCorpus::shared()->renderings;
        $symfony = self::symfony()->renderings;
        $translator = self::translator();
        $headers = [];
        foreach (TranslationResources::shippedLocales(RenderedCorpus::root()) as $locale) {
            $header = $translator->getCatalogue(str_replace('-', '_', $locale))->get('', TranslationResources::CACTI_DOMAIN);
            self::assertSame(1, preg_match('/^Plural-Forms: (.*)$/m', $header, $match), $locale);
            $headers[$match[1]][] = $locale;
            $expression = PluralFormsExpression::fromCatalogHeader($header);
            $expected = array_map('intval', $gettext[$locale]['plural_index']['cacti']);
            self::assertCount(1001, $expected);
            self::assertTrue($expected === $symfony[$locale]['plural_index']['cacti'], $locale);
            self::assertTrue($expected === array_map($expression->index(...), range(0, 1000)), $locale);
        }
        // The shipped rules, spelled as the catalogs spell them.
        self::assertSame([
            'nplurals=6; plural=n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 ? 4 : 5;',
            'nplurals=2; plural=n != 1;',
            'nplurals=2; plural=n > 1;',
            'nplurals=4; plural=(n == 1) ? 0 : ((n == 2) ? 1 : ((n > 10 && n % 10 == 0) ? 2 : 3));',
            'nplurals=2; plural=(n==0 || n==1);',
            'nplurals=1; plural=0;',
            'nplurals=3; plural=(n % 10 == 0 || n % 100 >= 11 && n % 100 <= 19) ? 0 : ((n % 10 == 1 && n % 100 != 11) ? 1 : 2);',
            'nplurals=3; plural=n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2;',
            'nplurals=2; plural=(n != 1);',
            'nplurals=3; plural=n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2;',
        ], array_keys($headers));
    }

    public function testMessagesWithALiteralPipeKeepTheirText(): void
    {
        $identities = array_values(array_filter(RenderedCorpus::identities(RenderedCorpus::root()), static fn(array $identity): bool => str_contains($identity[1] . $identity[2], '|')));
        self::assertCount(8, $identities);
        $gettext = RenderedCorpus::shared();
        $symfony = self::symfony();
        $translator = self::translator();
        $translated = 0;
        foreach (array_keys($gettext->renderings) as $locale) {
            foreach ($identities as [$context, $singular, $plural]) {
                self::assertSame(['', ''], [$context, $plural]);
                self::assertSame($gettext->result($locale, '__gettext', $singular), $symfony->result($locale, '__gettext', $singular), $locale . ' ' . $singular);
                if ($locale === 'en-US') {
                    continue;
                }
                $catalogue = $translator->getCatalogue(str_replace('-', '_', $locale));
                if ($catalogue->defines($singular, TranslationResources::CACTI_DOMAIN)) {
                    $translated++;
                    // Stored whole, not split into Symfony plural choices.
                    self::assertSame($gettext->result($locale, '__gettext', $singular)['value'], str_replace('%%', '%', $catalogue->get($singular, TranslationResources::CACTI_DOMAIN)));
                }
            }
        }
        self::assertGreaterThan(100, $translated);
        // Plugin fixtures: a | msgid and a plural whose forms contain |.
        self::assertSame('Links|Rechts', $symfony->result('de-DE', '__', 'Left|Right', 'i18nprobe')['value']);
        foreach (RenderedCorpus::COUNTS as $n) {
            self::assertSame($gettext->result('de-DE', '__n', 'One|item', 'Many|items', $n, 'i18nprobe'), $symfony->result('de-DE', '__n', 'One|item', 'Many|items', $n, 'i18nprobe'));
        }
    }

    public function testContextCollisionsStayDistinctMessages(): void
    {
        $identities = RenderedCorpus::identities(RenderedCorpus::root());
        $plain = [];
        foreach ($identities as [$context, $singular, $plural]) {
            if ($context === '' && $plural === '') {
                $plain[$singular] = true;
            }
        }
        $collisions = array_values(array_filter($identities, static fn(array $identity): bool => $identity[0] !== '' && $identity[2] === '' && isset($plain[$identity[1]])));
        $messages = array_values(array_unique(array_column($collisions, 1)));
        sort($messages);
        self::assertSame(['Archive', 'Clear', 'Delete', 'Go', 'May', 'Publish', 'Purge'], $messages);
        $gettext = RenderedCorpus::shared();
        $symfony = self::symfony();
        $distinct = 0;
        foreach (array_keys($gettext->renderings) as $locale) {
            foreach ($collisions as [$context, $singular]) {
                $plainBefore = $gettext->result($locale, '__gettext', $singular);
                $contextBefore = $gettext->result($locale, '__x', $context, $singular);
                self::assertSame($plainBefore, $symfony->result($locale, '__gettext', $singular), $locale . ' ' . $singular);
                self::assertSame($contextBefore, $symfony->result($locale, '__x', $context, $singular), $locale . ' ' . $context . ' ' . $singular);
                $distinct += (int) ($plainBefore !== $contextBefore);
            }
        }
        // Merging the context away, as Symfony's PoFileLoader does, would change these.
        self::assertGreaterThan(20, $distinct);
        $german = self::translator()->getCatalogue('de_DE');
        self::assertSame(['Löschen', 'Zurücksetzen'], [$german->get('Clear', 'cacti'), $german->get("filter: reset\x04Clear", 'cacti')]);
    }

    public function testFrenchIsServedAsFrAndFrFr(): void
    {
        $translator = self::translator();
        $loaded = (new GettextMoLoader())->load(RenderedCorpus::root() . '/locales/LC_MESSAGES/fr-FR.mo', 'fr_FR', 'cacti')->all('cacti');
        self::assertGreaterThan(4000, count($loaded));
        self::assertTrue($loaded === $translator->getCatalogue('fr_FR')->all('cacti'));
        self::assertTrue($loaded === $translator->getCatalogue('fr')->all('cacti'));
        self::assertSame([], $translator->getCatalogue('en')->all('cacti'));
    }

    public function testTheKernelTranslatorDoesNotLoadTheCactiDomainYet(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $translator = $kernel->getContainer()->get('translator');
            self::assertInstanceOf(TranslatorBagInterface::class, $translator);
            $domains = $translator->getCatalogue('de_DE')->getDomains();
            self::assertContains('console', $domains);
            self::assertNotContains(TranslationResources::CACTI_DOMAIN, $domains);
        } finally {
            $kernel->shutdown();
        }
    }

    private static function translator(): Translator
    {
        if (self::$translator === null) {
            self::$translator = new Translator('en');
            (new TranslationResources(RenderedCorpus::root()))->registerInto(self::$translator);
        }

        return self::$translator;
    }

    private static function remove(string $path): void
    {
        foreach (glob($path . '/{,.}*', GLOB_BRACE) ?: [] as $entry) {
            if (in_array(basename($entry), ['.', '..'], true)) {
                continue;
            }
            is_dir($entry) && !is_link($entry) ? self::remove($entry) : unlink($entry);
        }
        rmdir($path);
    }
}
