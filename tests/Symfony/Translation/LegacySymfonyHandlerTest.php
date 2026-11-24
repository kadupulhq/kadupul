<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Translation;

use Kadupul\Platform\Infrastructure\Legacy\LegacyTextDomain;
use PHPUnit\Framework\TestCase;

/**
 * The Symfony language handler through the legacy runtime: the corpus that
 * RenderedCorpus::shared() renders over php-gettext is rendered again with no
 * handler chosen, so include/global_languages.php selects, loads and calls
 * the Symfony handler itself, and every result must be the same.
 */
final class LegacySymfonyHandlerTest extends TestCase
{
    /** CACTI_LANGUAGE_HANDLER_* in include/global_constants.php. */
    private const int FALLBACK = 0;

    private const int PHP_GETTEXT = 1;

    private const int MOTRANSLATOR = 3;

    private const int SYMFONY = 4;

    /** @var list<string> */
    private array $owned = [];

    public static function tearDownAfterClass(): void
    {
        RenderedCorpus::release();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->owned) as $path) {
            self::remove($path);
        }
        $this->owned = [];
    }

    public function testNoChosenHandlerRendersEveryLocaleExactlyAsPhpGettext(): void
    {
        $root = RenderedCorpus::root();
        $gettext = RenderedCorpus::shared();
        // The counts each plural identity must be compared at.
        self::assertSame([], array_values(array_diff([0, 1, 2, 5, 11, 21, 101], RenderedCorpus::COUNTS)));
        $cache = $this->directory();

        $cold = RenderedCorpus::render($root, RenderedCorpus::locales($root), $gettext->calls, null, RenderedCorpus::NO_HANDLER, [], $cache);
        self::assertSameRendering($gettext, $cold, 'cold');
        $compiled = glob($cache . '/legacy-translations/catalogue.*.php') ?: [];
        // One compiled catalogue per shipped locale, holding all of its text domains.
        self::assertCount(43, $compiled);
        // A rebuilt catalogue is a new file renamed into place, so a new inode.
        $written = array_map('fileinode', $compiled);

        $warm = RenderedCorpus::render($root, RenderedCorpus::locales($root), $gettext->calls, null, RenderedCorpus::NO_HANDLER, [], $cache);
        self::assertSameRendering($gettext, $warm, 'warm');
        clearstatcache();
        // The second pass read the compiled catalogues; it did not rebuild them.
        self::assertSame($compiled, glob($cache . '/legacy-translations/catalogue.*.php') ?: []);
        self::assertSame($written, array_map('fileinode', $compiled));

        // Spot checks, so an oracle that broke would not make both sides agree on nothing.
        self::assertSame('Zielsysteme', $warm->result('de-DE', '__gettext', 'Devices')['value']);
        self::assertSame('Zurücksetzen', $warm->result('de-DE', '__x', 'filter: reset', 'Clear')['value']);
        self::assertSame(['Удалить запрос данных', 'Удалить запросы данных', 'Удалить запросы данных'], array_map(static fn(int $n): mixed => $warm->result('ru-RU', '__n', 'Delete Data Query', 'Delete Data Queries', $n)['value'], [1, 2, 5]));
        self::assertSame('Sondengeräte', $warm->result('de-DE', '__', 'Devices', 'i18nprobe')['value']);
        self::assertSame('Sonde entfernen', $warm->result('de-DE', '__x', 'probe', 'Delete', 'i18nprobe')['value']);
        self::assertSame('Devices', $warm->result('de-DE', '__gettext', 'Devices', 'absent')['value']);
        self::assertSame('Wert 0', $warm->result('de-DE', '__', 'Plain value', 'i18nprobe')['value']);
        self::assertSame('InvalidArgumentException', $warm->result('de-DE', '__n', 'Delete Data Query', 'Delete Data Queries', '2')['error']);
        self::assertStringStartsWith('Project-Id-Version: ', (string) $warm->result('de-DE', '__', '')['value']);
    }

    public function testASavedHandlerIsStillSelected(): void
    {
        $root = RenderedCorpus::root();
        $calls = [['__gettext', ['Devices']], ['__n', ['Delete Data Query', 'Delete Data Queries', 2]]];
        $locales = ['en-US', 'de-DE'];

        // php-gettext, as the settings page stores it. The shared corpus is rendered this way.
        $gettext = RenderedCorpus::render($root, $locales, $calls, null, RenderedCorpus::PHP_GETTEXT);
        self::assertSame(self::PHP_GETTEXT, $gettext->renderings['de-DE']['handler']);
        self::assertSame(['cacti' => 'gettext_reader', 'i18nprobe' => 'gettext_reader', 'folder' => 'gettext_reader'], $gettext->renderings['de-DE']['readers']);
        self::assertSame(self::PHP_GETTEXT, RenderedCorpus::shared()->renderings['ja-JP']['handler']);

        // "Use the First Handler Found" and the Symfony handler by its own value.
        foreach (['0', '4'] as $setting) {
            $symfony = RenderedCorpus::render($root, $locales, $calls, null, $setting);
            self::assertSame(self::SYMFONY, $symfony->renderings['de-DE']['handler'], $setting);
            self::assertSame(array_fill_keys(['cacti', 'i18nprobe', 'folder'], LegacyTextDomain::class), $symfony->renderings['de-DE']['readers'], $setting);
            self::assertSame($gettext->renderings['de-DE']['values'], $symfony->renderings['de-DE']['values'], $setting);
            self::assertSame(self::FALLBACK, $symfony->renderings['en-US']['handler'], $setting);
        }

        // Oscarotero needs PHP 8.0 or older and MoTranslator is not shipped, so
        // without their libraries both settings get the first handler found.
        foreach (['2', '3'] as $setting) {
            $first = RenderedCorpus::render($root, $locales, $calls, null, $setting, ['de-DE' => ['de-DE', self::SYMFONY]]);
            self::assertSame(self::SYMFONY, $first->renderings['de-DE']['handler'], $setting);
        }
    }

    public function testMoTranslatorIsSelectedWhereItsLibraryIsInstalled(): void
    {
        $root = RenderedCorpus::root();
        // The runtime's own files, plus an installed MoTranslator.
        $copy = $this->directory();
        mkdir($copy . '/include/vendor/motranslator', 0700, true);
        $links = [
            $copy . '/locales' => $root . '/locales',
            $copy . '/include/global_constants.php' => $root . '/include/global_constants.php',
            $copy . '/include/global_languages.php' => $root . '/include/global_languages.php',
            $copy . '/include/vendor/autoload.php' => $root . '/include/vendor/autoload.php',
            $copy . '/include/vendor/phpgettext' => $root . '/include/vendor/phpgettext',
            $copy . '/include/vendor/motranslator/autoload.php' => $root . '/tests/Symfony/Fixtures/translation/motranslator-autoload.php',
        ];
        foreach ($links as $link => $target) {
            self::assertTrue(symlink($target, $link));
        }
        $calls = [['__gettext', ['Devices']]];

        $selected = RenderedCorpus::render($copy, ['en-US', 'de-DE'], $calls, null, '3', ['de-DE' => ['de-DE', self::MOTRANSLATOR]]);
        self::assertSame(self::MOTRANSLATOR, $selected->renderings['de-DE']['handler']);
        self::assertSame(array_fill_keys(['cacti', 'i18nprobe', 'folder'], 'PhpMyAdmin\MoTranslator\Translator'), $selected->renderings['de-DE']['readers']);

        // Installing it does not change the handler nobody chose.
        $default = RenderedCorpus::render($copy, ['en-US', 'de-DE'], $calls, null, RenderedCorpus::NO_HANDLER);
        self::assertSame(self::SYMFONY, $default->renderings['de-DE']['handler']);
        self::assertSame('Zielsysteme', $default->result('de-DE', '__gettext', 'Devices')['value']);
    }

    public function testALocaleWithoutACatalogRendersEnglish(): void
    {
        $root = RenderedCorpus::root();
        $gettext = RenderedCorpus::shared();
        // zh-HK is a known locale that ships no catalog; xx-XX is not a locale.
        $expect = ['zh-HK' => ['en-US', self::FALLBACK], 'xx-XX' => ['en-US', self::FALLBACK]];
        $rendered = RenderedCorpus::render($root, ['en-US', 'zh-HK', 'xx-XX'], $gettext->calls, null, RenderedCorpus::NO_HANDLER, $expect);
        foreach (['en-US', 'zh-HK', 'xx-XX'] as $locale) {
            $rendering = $rendered->renderings[$locale];
            self::assertSame(['en-US', self::FALLBACK, [], []], [$rendering['locale'], $rendering['handler'], $rendering['readers'], $rendering['log']], $locale);
            self::assertTrue($gettext->renderings['en-US']['values'] === $rendering['values'], $locale);
            self::assertSame($gettext->renderings['en-US']['errors'], $rendering['errors'], $locale);
        }
    }

    public function testAnUnreadableCoreCatalogRendersEnglishAndKeepsThePluginDomains(): void
    {
        $root = RenderedCorpus::root();
        $gettext = RenderedCorpus::shared();
        $work = $this->directory();
        $german = (string) file_get_contents($root . '/locales/LC_MESSAGES/de-DE.mo');
        // Truncated inside the string tables, and a file that is not a catalog at all.
        file_put_contents($work . '/truncated.mo', substr($german, 0, intdiv(strlen($german), 3)));
        file_put_contents($work . '/garbage.mo', str_repeat("not a catalog\n", 64));
        mkdir($work . '/cache', 0700);

        $rendered = RenderedCorpus::render($root, ['en-US', 'de-DE', 'fr-FR'], $gettext->calls, null, RenderedCorpus::NO_HANDLER, [], $work . '/cache', ['de-DE.mo' => $work . '/truncated.mo', 'fr-FR.mo' => $work . '/garbage.mo']);

        $english = $rendered->renderings['en-US'];
        $french = $rendered->renderings['fr-FR'];
        self::assertSame(['fr-FR', self::SYMFONY, []], [$french['locale'], $french['handler'], $french['readers']]);
        self::assertTrue($english['values'] === $french['values']);
        self::assertSame($english['errors'], $french['errors']);
        self::assertCount(1, $french['log']);
        self::assertStringContainsString("text domain 'cacti' is shown in English: The file is not a gettext catalog.", $french['log'][0]);

        $german = $rendered->renderings['de-DE'];
        self::assertSame(['de-DE', self::SYMFONY], [$german['locale'], $german['handler']]);
        self::assertSame(array_fill_keys(['i18nprobe', 'folder'], LegacyTextDomain::class), $german['readers']);
        self::assertCount(1, $german['log']);
        self::assertStringStartsWith("i18n: WARNING: Language file '", $german['log'][0]);
        self::assertStringContainsString("text domain 'cacti' is shown in English: ", $german['log'][0]);
        $differing = 0;
        foreach ($rendered->calls as $index => [, $arguments]) {
            $plugin = in_array(end($arguments), RenderedCorpus::PLUGINS, true);
            // Core text is English; a call that names a plugin domain is what php-gettext renders.
            $expected = $plugin ? $gettext->renderings['de-DE'] : $english;
            $differing += (int) ([$expected['values'][$index], $expected['errors'][$index] ?? null] !== [$german['values'][$index], $german['errors'][$index] ?? null]);
        }
        // Only plugin calls that fall through to the core catalog differ from php-gettext.
        self::assertSame('Sondengeräte', $rendered->result('de-DE', '__', 'Devices', 'i18nprobe')['value']);
        self::assertSame('Devices', $rendered->result('de-DE', '__gettext', 'Devices')['value']);
        self::assertSame(0, $differing);
        // Nothing was compiled for a set of catalogs that could not all be read.
        self::assertSame([], glob($work . '/cache/legacy-translations/catalogue.fr_FR.*') ?: []);
        self::assertSame([], glob($work . '/cache/legacy-translations/catalogue.de_DE.*') ?: []);
    }

    public function testAnUnwritableCacheDirectoryStillRendersTheTranslations(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Directory permissions do not bind the superuser.');
        }
        $root = RenderedCorpus::root();
        $gettext = RenderedCorpus::shared();
        $calls = array_slice($gettext->calls, 0, 400);
        $expected = array_slice($gettext->renderings['de-DE']['values'], 0, 400);
        $work = $this->directory();

        // var/cache exists and cannot be written, and var/cache is not a directory at all.
        mkdir($work . '/readonly', 0500);
        file_put_contents($work . '/file', '');
        foreach ([$work . '/readonly', $work . '/file'] as $cache) {
            $rendered = RenderedCorpus::render($root, ['en-US', 'de-DE'], $calls, null, RenderedCorpus::NO_HANDLER, [], $cache);
            self::assertSame(self::SYMFONY, $rendered->renderings['de-DE']['handler'], $cache);
            self::assertTrue($expected === $rendered->renderings['de-DE']['values'], $cache);
            self::assertSame([], $rendered->renderings['de-DE']['log'], $cache);
        }
        self::assertSame(['.', '..'], scandir($work . '/readonly'));
        self::assertSame('', file_get_contents($work . '/file'));
    }

    private static function assertSameRendering(RenderedCorpus $gettext, RenderedCorpus $symfony, string $pass): void
    {
        // Booleans and counts, not arrays: PHPUnit exports every asserted value, even on success.
        self::assertTrue($gettext->calls === $symfony->calls);
        self::assertSame(array_keys($gettext->renderings), array_keys($symfony->renderings));
        self::assertSame(44, count($symfony->renderings));
        foreach ($gettext->renderings as $locale => $expected) {
            $actual = $symfony->renderings[$locale];
            self::assertSame($locale, $actual['locale'], $pass);
            self::assertSame([], $actual['log'], $pass . ' ' . $locale);
            self::assertSame(array_keys($expected['domains']), array_keys($actual['domains']), $pass . ' ' . $locale);
            if ($locale === 'en-US') {
                self::assertSame([self::FALLBACK, []], [$actual['handler'], $actual['readers']], $pass);
            } else {
                // Every domain php-gettext read was answered by the Symfony handler.
                self::assertSame(self::PHP_GETTEXT, $expected['handler'], $pass . ' ' . $locale);
                self::assertSame(self::SYMFONY, $actual['handler'], $pass . ' ' . $locale);
                self::assertArrayHasKey('cacti', $actual['readers'], $pass . ' ' . $locale);
                self::assertSame(array_fill_keys(array_keys($expected['domains']), LegacyTextDomain::class), $actual['readers'], $pass . ' ' . $locale);
            }
            $differing = 0;
            $examples = [];
            foreach ($gettext->calls as $index => $call) {
                $before = [$expected['values'][$index], $expected['errors'][$index] ?? null, $expected['warnings'][$index] ?? []];
                $after = [$actual['values'][$index], $actual['errors'][$index] ?? null, $actual['warnings'][$index] ?? []];
                if ($before !== $after && $differing++ < 5) {
                    $examples[] = json_encode([$call, $before, $after], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                }
            }
            self::assertSame(0, $differing, $pass . ' ' . $locale . ' differs from php-gettext, for example ' . implode("\n", $examples));
        }
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir() . '/kadupul-legacy-handler-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $this->owned[] = $directory;

        return $directory;
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        chmod($path, 0700);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
