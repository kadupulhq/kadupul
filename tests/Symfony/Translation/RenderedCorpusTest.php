<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Translation;

use PHPUnit\Framework\TestCase;

/**
 * The corpus is the oracle for moving legacy __() onto Symfony Translation, so
 * these tests pin that it really is the legacy runtime's output and that it
 * cannot silently shrink to nothing.
 */
final class RenderedCorpusTest extends TestCase
{
    public static function tearDownAfterClass(): void
    {
        RenderedCorpus::release();
    }

    public function testCorpusCoversEveryIdentityAndEveryLocale(): void
    {
        $root = RenderedCorpus::root();
        $pot = (string) file_get_contents($root . '/locales/po/cacti.pot');
        $identities = RenderedCorpus::identities($root);
        // Every msgid line of the POT except its header entry.
        self::assertSame(preg_match_all('/^msgid "/m', $pot) - 1, count($identities));
        $historical = json_decode((string) file_get_contents($root . '/locales/historical-compatibility.json'), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($historical);
        self::assertSame(195, count($historical['identities']));
        foreach ($historical['identities'] as $item) {
            self::assertTrue(in_array($item['identity'], $identities, true), implode(' / ', $item['identity']));
        }
        $plurals = count(array_filter($identities, static fn(array $identity): bool => $identity[2] !== ''));
        $contexts = count(array_filter($identities, static fn(array $identity): bool => $identity[0] !== ''));
        self::assertGreaterThan(0, $plurals);
        self::assertGreaterThan(0, $contexts);

        $corpus = RenderedCorpus::shared();
        // Counts, not arrays: PHPUnit exports every asserted value, even on success.
        self::assertSame(count($identities) - $plurals + $plurals * count(RenderedCorpus::COUNTS) + count(RenderedCorpus::fixedCalls()), count($corpus->calls));
        self::assertSame(RenderedCorpus::locales($root), array_keys($corpus->renderings));
        self::assertSame(24, count($corpus->renderings));
        // CACTI_LANGUAGE_HANDLER_DEFAULT and _PHPGETTEXT in include/global_constants.php.
        [$fallback, $phpGettext] = [0, 1];
        foreach ($corpus->renderings as $locale => $rendering) {
            self::assertSame($locale, $rendering['locale']);
            self::assertSame(count($corpus->calls), count($rendering['values']), $locale);
            if ($locale === 'en-US') {
                self::assertSame($fallback, $rendering['handler']);
                self::assertSame([], $rendering['domains']);
                continue;
            }
            self::assertSame($phpGettext, $rendering['handler'], $locale);
            self::assertStringEndsWith('/locales/LC_MESSAGES/' . $locale . '.mo', $rendering['domains']['cacti'], $locale);
            // uk-UA and zh-TW are mostly untranslated or fuzzy; every catalog still changes something.
            self::assertGreaterThan(1000, $corpus->translatedCount($locale), $locale);
        }
        self::assertSame(['cacti', 'i18nprobe', 'folder'], array_keys($corpus->renderings['de-DE']['domains']));
        self::assertStringEndsWith('/plugins/folder/locales/LC_MESSAGES/german_germany.mo', $corpus->renderings['de-DE']['domains']['folder']);
    }

    public function testRenderingsAreTheLegacyRuntimeOutput(): void
    {
        $corpus = RenderedCorpus::shared();
        $value = static fn(string $locale, string $function, int|string ...$arguments): mixed => $corpus->result($locale, $function, ...$arguments)['value'];

        self::assertSame('Zielsysteme', $value('de-DE', '__gettext', 'Devices'));
        self::assertSame('Devices', $value('en-US', '__gettext', 'Devices'));
        self::assertSame('Устройства', $value('ru-RU', '__gettext', 'Devices'));
        // Context and no context are different messages.
        self::assertSame('Löschen', $value('de-DE', '__x', 'dropdown action', 'Delete'));
        self::assertSame(['Löschen', 'Zurücksetzen'], [$value('de-DE', '__gettext', 'Clear'), $value('de-DE', '__x', 'filter: reset', 'Clear')]);
        // gettext's own Plural-Forms choose the form: Russian has three.
        $russian = array_map(static fn(int $n): mixed => $value('ru-RU', '__n', 'Delete Data Query', 'Delete Data Queries', $n), [1, 2, 5]);
        self::assertCount(3, array_unique($russian));
        // An empty compiled plural form renders as an empty string.
        self::assertSame('', $value('de-DE', '__n', '%d slot', '%d slots', 2, 'i18nprobe'));
        self::assertSame('%d Platz', $value('de-DE', '__n', '%d slot', '%d slots', 1, 'i18nprobe'));
        // A literal | is ordinary text.
        self::assertSame('Links|Rechts', $value('de-DE', '__', 'Left|Right', 'i18nprobe'));
        self::assertSame('Viele|Elemente', $value('de-DE', '__n', 'One|item', 'Many|items', 3, 'i18nprobe'));
        self::assertSame('Version 1.2 | Kadupul', $value('fr-FR', '__', 'Version %s | %s', '1.2', 'Kadupul'));
        // %% is unescaped by __gettext, before __() decides whether to sprintf.
        self::assertSame('Wert 0', $value('de-DE', '__', 'Plain value', 'i18nprobe'));
        self::assertSame('ValueError', $corpus->result('de-DE', '__', 'Usage %d%%', 50, 'i18nprobe')['error']);
        // php-gettext refuses a non-int count once a catalog is loaded.
        self::assertSame('InvalidArgumentException', $corpus->result('de-DE', '__n', 'Delete Data Query', 'Delete Data Queries', '2')['error']);
        self::assertSame('Delete Data Queries', $value('en-US', '__n', 'Delete Data Query', 'Delete Data Queries', '2'));
        // __xn prefixes the plural with the context too, so php-gettext never
        // finds a contextual plural entry.
        self::assertSame('Items', $value('de-DE', '__xn', 'probe', 'Item', 'Items', 2, 'i18nprobe'));
        self::assertSame('Sonde entfernen', $value('de-DE', '__x', 'probe', 'Delete', 'i18nprobe'));
        self::assertSame('Dienstag, 14 November 2023 22:13', $value('de-DE', '__date', 'l, j F Y H:i', 1700000000));
        self::assertSame('Tuesday, 14 November 2023 22:13', $value('en-US', '__date', 'l, j F Y H:i', 1700000000));
    }

    public function testLastArgumentIsADomainOnlyWhenThatDomainIsLoaded(): void
    {
        $corpus = RenderedCorpus::shared();
        $value = static fn(string $locale, int|string ...$arguments): mixed => $corpus->result($locale, '__', ...$arguments)['value'];
        $rrd = ['Website does not have write access to %s, may be unable to create/update RRDs', 'folder'];

        // German has a "folder" plugin catalog, so lib/rrd.php's argument names a
        // domain that lacks the message and the text stays English.
        self::assertSame('Website does not have write access to folder, may be unable to create/update RRDs', $value('de-DE', ...$rrd));
        self::assertSame('Ordner', $value('de-DE', 'Folder', 'folder'));
        // Elsewhere it is a sprintf argument to the core translation.
        self::assertStringContainsString(' folder', (string) $value('fr-FR', ...$rrd));
        self::assertStringStartsNotWith('Website', (string) $value('fr-FR', ...$rrd));
        self::assertSame('Folder', $value('fr-FR', 'Folder', 'folder'));
        // A plugin domain shadows the core catalog only for its own messages.
        self::assertSame('Sondengeräte', $value('de-DE', 'Devices', 'i18nprobe'));
        self::assertSame('Équipements', $value('fr-FR', 'Devices', 'i18nprobe'));
        self::assertSame('5 Sondenminuten', $value('de-DE', '%d Minutes', 5, 'i18nprobe'));
        // 'cacti' and names no plugin uses select the core catalog.
        self::assertSame('Zielsysteme', $value('de-DE', 'Devices', 'cacti'));
        self::assertSame($corpus->result('de-DE', '__gettext', 'Graph Template Height')['value'], $value('de-DE', 'Graph Template Height', 'pagkage'));
        self::assertSame($corpus->result('de-DE', '__gettext', 'Differences')['value'], $value('de-DE', 'Differences', 'package'));
    }

    public function testPluralIndexIsRecordedForEveryLoadedDomain(): void
    {
        foreach (RenderedCorpus::shared()->renderings as $locale => $rendering) {
            self::assertSame(array_keys($rendering['domains']), array_keys($rendering['plural_index']), $locale);
            foreach ($rendering['plural_index'] as $domain => $indexes) {
                self::assertSame(range(0, 1000), array_keys($indexes), $locale . ' ' . $domain);
            }
        }
        $indexes = RenderedCorpus::shared()->renderings;
        // hi-IN's header puts n = 0 and 1 in form 1.
        self::assertSame([1, 1, 0], array_map(static fn(int $n): int => (int) $indexes['hi-IN']['plural_index']['cacti'][$n], [0, 1, 2]));
        self::assertSame([3, 0, 1, 2, 3], array_map(static fn(int $n): int => (int) $indexes['he-IL']['plural_index']['cacti'][$n], [0, 1, 2, 20, 21]));
        self::assertSame([0, 1, 2, 3, 4, 5], array_map(static fn(int $n): int => (int) $indexes['ar-SA']['plural_index']['cacti'][$n], [0, 1, 2, 3, 11, 100]));
    }

    public function testEmptyCorpusIsRefused(): void
    {
        $root = RenderedCorpus::root();
        foreach ([[[], RenderedCorpus::fixedCalls()], [RenderedCorpus::locales($root), []]] as [$locales, $calls]) {
            try {
                RenderedCorpus::render($root, $locales, $calls);
                self::fail('An empty corpus was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertSame('An empty corpus proves nothing.', $exception->getMessage());
            }
        }
    }

    public function testLocaleWithNoTranslatedEntryIsRefused(): void
    {
        $this->expectExceptionMessage('de-DE has no translated entries.');
        RenderedCorpus::render(RenderedCorpus::root(), ['en-US', 'de-DE'], [['__gettext', ['No catalog has this message']]]);
    }

    public function testMissingGettextReaderFailsInsteadOfRenderingEnglish(): void
    {
        $root = RenderedCorpus::root();
        // The runtime's own files and catalogs, without include/vendor/phpgettext.
        $copy = sys_get_temp_dir() . '/kadupul-corpus-root-' . bin2hex(random_bytes(8));
        mkdir($copy . '/include', 0700, true);
        $links = [$copy . '/locales' => $root . '/locales'];
        foreach (['global_constants.php', 'global_languages.php'] as $file) {
            $links[$copy . '/include/' . $file] = $root . '/include/' . $file;
        }
        try {
            foreach ($links as $link => $target) {
                self::assertTrue(symlink($target, $link));
            }
            RenderedCorpus::render($copy, ['en-US', 'de-DE'], [['__gettext', ['Devices']]]);
            self::fail('The corpus rendered without php-gettext.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Legacy oracle for de-DE exited 3: legacy runtime selected locale de-DE with handler 0', $exception->getMessage());
        } finally {
            array_map('unlink', array_keys($links));
            rmdir($copy . '/include');
            rmdir($copy);
        }
    }
}
