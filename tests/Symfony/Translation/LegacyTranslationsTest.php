<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Translation;

use Kadupul\Platform\Infrastructure\Legacy\LegacyTextDomain;
use Kadupul\Platform\Infrastructure\Legacy\LegacyTranslations;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue cache and failure handling of the Symfony language handler.
 * LegacySymfonyHandlerTest compares what it renders with php-gettext.
 */
final class LegacyTranslationsTest extends TestCase
{
    private const string PROBE = __DIR__ . '/../Fixtures/translation/plugins/i18nprobe/locales/LC_MESSAGES/de-DE.mo';

    private const string FOLDER = __DIR__ . '/../Fixtures/translation/plugins/folder/locales/LC_MESSAGES/german_germany.mo';

    private string $work = '';

    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir() . '/kadupul-legacy-translations-' . bin2hex(random_bytes(8));
        mkdir($this->work . '/cache', 0700, true);
    }

    protected function tearDown(): void
    {
        self::remove($this->work);
    }

    public function testAnswersTheLookupsOfTheLegacyHelpers(): void
    {
        $translations = new LegacyTranslations('de-DE', ['i18nprobe' => self::PROBE], $this->work . '/cache');
        self::assertTrue($translations->cached());
        self::assertSame([], $translations->failures());
        self::assertSame(['i18nprobe'], array_keys($translations->domains()));
        $probe = $translations->domains()['i18nprobe'];

        self::assertSame('Sondengeräte', $probe->translate('Devices'));
        self::assertSame('Sonde entfernen', $probe->translate("probe\x04Delete"));
        self::assertSame("other\x04Delete", $probe->translate("other\x04Delete"));
        self::assertSame('Untranslated probe', $probe->translate('Untranslated probe'));
        // %% is left for __uf().
        self::assertSame('Auslastung %d%%', $probe->translate('Usage %d%%'));
        self::assertStringContainsString("Plural-Forms: nplurals=2; plural=n != 1;\n", (string) $probe->translate(''));
        // php-gettext looks null up as the empty msgid and returns other values untouched.
        self::assertSame($probe->translate(''), $probe->translate(null));
        self::assertSame(404, $probe->translate(404));
        self::assertSame(1.5, $probe->translate(1.5));

        self::assertSame(['%d Sonden', '%d Sonde', '%d Sonden'], array_map(static fn(int $n): ?string => $probe->ngettext('%d probe', '%d probes', $n), [0, 1, 2]));
        // An empty compiled form stays empty.
        self::assertSame(['%d Platz', ''], [$probe->ngettext('%d slot', '%d slots', 1), $probe->ngettext('%d slot', '%d slots', 2)]);
        self::assertSame(['One', 'Many', 'Many'], array_map(static fn(int $n): ?string => $probe->ngettext('One', 'Many', $n), [1, 0, 2]));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Select_string only accepts integers: 2');
        $probe->ngettext('%d probe', '%d probes', '2');
    }

    public function testSelectsFormsWithTheCatalogsOwnRule(): void
    {
        // Symfony's own rule for a locale named "de" has two forms; this catalog declares three.
        $catalog = $this->catalog('rule.mo', ['' => "Plural-Forms: nplurals=3; plural=n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2;\n", "file\0files" => "one\0few\0many", "short\0shorts" => 'only']);
        $domain = (new LegacyTranslations('de-DE', ['rule' => $catalog], null))->domains()['rule'];
        self::assertSame(['many', 'one', 'few', 'many', 'many', 'one', 'one'], array_map(static fn(int $n): ?string => $domain->ngettext('file', 'files', $n), [0, 1, 2, 5, 11, 21, 101]));
        // Fewer forms than the rule selects: php-gettext yields null there.
        self::assertSame(['only', null], [$domain->ngettext('short', 'shorts', 1), $domain->ngettext('short', 'shorts', 2)]);
    }

    public function testCompiledCataloguesAreKeptPerLocaleAndPerSetOfCatalogs(): void
    {
        $cache = $this->work . '/cache';
        $german = new LegacyTranslations('de-DE', ['i18nprobe' => self::PROBE], $cache);
        self::assertCount(1, $this->compiled());
        // The same catalogs again reuse the file; another domain set or locale does not.
        new LegacyTranslations('de-DE', ['i18nprobe' => self::PROBE], $cache);
        self::assertCount(1, $this->compiled());
        $both = new LegacyTranslations('de-DE', ['i18nprobe' => self::PROBE, 'folder' => self::FOLDER], $cache);
        self::assertCount(2, $this->compiled());
        // The same file under another domain name or locale is a different catalogue.
        $renamed = new LegacyTranslations('de-DE', ['folder' => self::PROBE], $cache);
        $austrian = new LegacyTranslations('de-AT', ['i18nprobe' => self::FOLDER], $cache);
        self::assertCount(4, $this->compiled());
        self::assertSame(['catalogue.de_AT.', 'catalogue.de_DE.', 'catalogue.de_DE.', 'catalogue.de_DE.'], array_map(static fn(string $file): string => substr(basename($file), 0, 16), $this->compiled()));

        foreach ([$german, $both, new LegacyTranslations('de-DE', ['i18nprobe' => self::PROBE, 'folder' => self::FOLDER], $cache)] as $translations) {
            self::assertTrue($translations->cached());
            self::assertSame('Sondengeräte', $translations->domains()['i18nprobe']->translate('Devices'));
        }
        self::assertSame(['Ordner', 'Devices'], [$both->domains()['folder']->translate('Folder'), $both->domains()['folder']->translate('Devices')]);
        self::assertSame(['Folder', 'Sondengeräte'], [$renamed->domains()['folder']->translate('Folder'), $renamed->domains()['folder']->translate('Devices')]);
        self::assertSame(['Ordner', 'Devices'], [$austrian->domains()['i18nprobe']->translate('Folder'), $austrian->domains()['i18nprobe']->translate('Devices')]);
    }

    public function testAReplacedCatalogIsRecompiled(): void
    {
        $cache = $this->work . '/cache';
        $catalog = $this->catalog('probe.mo', ['' => "Plural-Forms: nplurals=2; plural=n != 1;\n", 'Devices' => 'Geräte']);
        self::assertSame('Geräte', (new LegacyTranslations('de-DE', ['probe' => $catalog], $cache))->domains()['probe']->translate('Devices'));
        [$compiled] = $this->compiled();
        $inode = fileinode($compiled);

        // Unchanged: served from the compiled file.
        self::assertSame('Geräte', (new LegacyTranslations('de-DE', ['probe' => $catalog], $cache))->domains()['probe']->translate('Devices'));
        clearstatcache();
        self::assertSame($inode, fileinode($compiled));

        // Replaced after the catalogue was compiled.
        touch($compiled, time() - 60);
        $this->catalog('probe.mo', ['' => "Plural-Forms: nplurals=2; plural=n != 1;\n", 'Devices' => 'Zielsysteme', 'Folder' => 'Ordner']);
        clearstatcache();
        $replaced = new LegacyTranslations('de-DE', ['probe' => $catalog], $cache);
        self::assertTrue($replaced->cached());
        self::assertSame(['Zielsysteme', 'Ordner'], [$replaced->domains()['probe']->translate('Devices'), $replaced->domains()['probe']->translate('Folder')]);
        clearstatcache();
        self::assertSame([$compiled], $this->compiled());
        self::assertNotSame($inode, fileinode($compiled));
    }

    public function testOneUnreadableCatalogCostsOnlyItsOwnDomain(): void
    {
        $truncated = $this->work . '/truncated.mo';
        file_put_contents($truncated, substr((string) file_get_contents(self::PROBE), 0, 90));
        $catalogs = ['i18nprobe' => self::PROBE, 'truncated' => $truncated, 'missing' => $this->work . '/missing.mo', 'folder' => self::FOLDER];
        foreach ([$this->work . '/cache', null] as $cache) {
            $translations = new LegacyTranslations('de-DE', $catalogs, $cache);
            self::assertFalse($translations->cached());
            self::assertSame(['i18nprobe', 'folder'], array_keys($translations->domains()));
            self::assertSame(['truncated' => 'The gettext catalog tables are out of bounds.', 'missing' => 'The gettext catalog does not exist.'], $translations->failures());
            self::assertSame('Sondengeräte', $translations->domains()['i18nprobe']->translate('Devices'));
            self::assertSame('Ordner', $translations->domains()['folder']->translate('Folder'));
        }
        self::assertSame([], $this->compiled());

        // A catalog that vanished after the legacy bootstrap found it, listed first.
        $translations = new LegacyTranslations('de-DE', ['missing' => $this->work . '/missing.mo', 'i18nprobe' => self::PROBE], $this->work . '/cache');
        self::assertSame(['missing' => 'The gettext catalog does not exist.'], $translations->failures());
        self::assertSame('Sondengeräte', $translations->domains()['i18nprobe']->translate('Devices'));
        self::assertSame([], $this->compiled());
    }

    public function testAnUnusableCacheIsReadAround(): void
    {
        $cache = $this->work . '/cache';
        $expect = function (LegacyTranslations $translations): void {
            self::assertFalse($translations->cached());
            self::assertSame([], $translations->failures());
            self::assertSame('Sondengeräte', $translations->domains()['i18nprobe']->translate('Devices'));
        };

        // No cache directory was given.
        $expect(new LegacyTranslations('de-DE', ['i18nprobe' => self::PROBE], null));
        self::assertSame([], $this->compiled());

        // The cache path is below a regular file.
        file_put_contents($this->work . '/file', '');
        $expect(new LegacyTranslations('de-DE', ['i18nprobe' => self::PROBE], $this->work . '/file/cache'));

        // A directory that cannot be written and holds nothing for the locale.
        mkdir($this->work . '/readonly/cache', 0700, true);
        self::assertTrue((new LegacyTranslations('de-DE', ['i18nprobe' => self::PROBE], $this->work . '/readonly/cache'))->cached());
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            chmod($this->work . '/readonly/cache', 0500);
            chmod($this->work . '/readonly', 0500);
            try {
                $expect(new LegacyTranslations('fr-FR', ['i18nprobe' => self::PROBE], $this->work . '/readonly/cache'));
                $expect(new LegacyTranslations('de-DE', ['i18nprobe' => self::PROBE], $this->work . '/readonly/absent/cache'));
                // What was compiled while it could be written is still read.
                $compiled = new LegacyTranslations('de-DE', ['i18nprobe' => self::PROBE], $this->work . '/readonly/cache');
                self::assertTrue($compiled->cached());
                self::assertSame('Sondengeräte', $compiled->domains()['i18nprobe']->translate('Devices'));
            } finally {
                chmod($this->work . '/readonly', 0700);
                chmod($this->work . '/readonly/cache', 0700);
            }
        }

        // A compiled catalogue that no longer parses, and one that returns something else.
        self::assertTrue((new LegacyTranslations('de-DE', ['i18nprobe' => self::PROBE], $cache))->cached());
        [$compiled] = $this->compiled();
        foreach (['<?php return [', '<?php return 1;'] as $damaged) {
            file_put_contents($compiled, $damaged);
            $expect(new LegacyTranslations('de-DE', ['i18nprobe' => self::PROBE], $cache));
        }
    }

    public function testADomainWithoutMessagesIsStillLoaded(): void
    {
        $empty = $this->catalog('empty.mo', []);
        foreach ([null, $this->work . '/cache', $this->work . '/cache'] as $cache) {
            $translations = new LegacyTranslations('de-DE', ['empty' => $empty], $cache);
            self::assertSame([], $translations->failures());
            self::assertInstanceOf(LegacyTextDomain::class, $translations->domains()['empty'] ?? null);
            self::assertSame('Devices', $translations->domains()['empty']->translate('Devices'));
            // php-gettext's default rule without a header.
            self::assertSame(['Many', 'One', 'Many'], array_map(static fn(int $n): ?string => $translations->domains()['empty']->ngettext('One', 'Many', $n), [0, 1, 2]));
        }
    }

    /** @return list<string> */
    private function compiled(): array
    {
        $files = glob($this->work . '/cache/catalogue.*.php') ?: [];
        sort($files);

        return $files;
    }

    /**
     * Writes a little-endian .mo file with the given entries.
     *
     * @param array<string, string> $entries
     */
    private function catalog(string $name, array $entries): string
    {
        ksort($entries, SORT_STRING);
        $count = count($entries);
        $offset = 28 + $count * 16;
        $originals = $translations = $pool = '';
        foreach ([array_map('strval', array_keys($entries)), array_values($entries)] as $index => $strings) {
            foreach ($strings as $string) {
                $entry = pack('VV', strlen($string), $offset + strlen($pool));
                $index === 0 ? $originals .= $entry : $translations .= $entry;
                $pool .= $string . "\0";
            }
        }
        $path = $this->work . '/' . $name;
        file_put_contents($path, pack('V7', 0x950412de, 0, $count, 28, 28 + $count * 8, 0, 0) . $originals . $translations . $pool);

        return $path;
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
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
