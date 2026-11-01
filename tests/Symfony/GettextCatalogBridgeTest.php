<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\IdentityAccess\Contract\LocalePreference;
use Kadupul\Inventory\Application\Port\SiteEditor;
use Kadupul\Inventory\Domain\Site;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Infrastructure\Symfony\ConsoleTranslations;
use Kadupul\Platform\Infrastructure\Symfony\GettextCatalog;
use Kadupul\Platform\Infrastructure\Symfony\GettextCatalogLoader;
use Kadupul\Platform\Infrastructure\Symfony\InventoryLocaleSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Translation\Loader\MoFileLoader;
use Symfony\Component\Translation\Loader\PoFileLoader;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GettextCatalogBridgeTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    private static ?Kernel $kernel = null;

    public static function tearDownAfterClass(): void
    {
        self::$kernel?->shutdown();
        self::$kernel = null;
    }

    private static function translator(): TranslatorInterface
    {
        if (self::$kernel === null) {
            self::$kernel = new Kernel('test', true);
            self::$kernel->boot();
        }
        $translator = self::$kernel->getContainer()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return $translator;
    }

    /** @return list<string> */
    private static function shippedLocales(): array
    {
        $manifest = json_decode((string) file_get_contents(self::ROOT . '/locales/catalogs.json'), true, 16, JSON_THROW_ON_ERROR);

        return $manifest['translated_locales'];
    }

    public function testEveryShippedCatalogParsesAndFillsModuleKeys(): void
    {
        $locales = self::shippedLocales();
        $files = array_map(static fn(string $path): string => basename($path, '.po'), glob(self::ROOT . '/locales/po/*.po') ?: []);
        sort($files);
        self::assertSame($locales, $files);
        self::assertCount(23, $locales);
        $keys = [];
        foreach (glob(self::ROOT . '/config/translations/*.en.yaml') ?: [] as $source) {
            $keys = array_merge($keys, array_map('strval', array_keys(Yaml::parseFile($source))));
        }
        $keys = array_values(array_unique($keys));
        self::assertGreaterThan(1000, count($keys));
        $counts = [];
        foreach ($locales as $locale) {
            $path = self::ROOT . '/locales/po/' . $locale . '.po';
            self::assertGreaterThan(400, count((new PoFileLoader())->load($path, 'en')->all('messages')), $locale);
            self::assertMatchesRegularExpression('/^"Plural-Forms: nplurals=\d+; plural=[^"]+;\\\\n"$/m', (string) file_get_contents($path), $locale);
            $catalog = GettextCatalog::fromFile($path);
            // The compiled .mo is what legacy __() reads; plain bridged text must match it.
            $compiled = (new MoFileLoader())->load(self::ROOT . '/locales/LC_MESSAGES/' . $locale . '.mo', 'en')->all('messages');
            $counts[$locale] = 0;
            foreach ($keys as $key) {
                $message = $catalog->translate($key);
                if ($message === null) {
                    continue;
                }
                $counts[$locale]++;
                if (!str_contains($key, '%') && isset($compiled[$key])) {
                    self::assertSame(str_replace('%%', '%', $compiled[$key]), $message, $locale . ' ' . $key);
                }
            }
        }
        // zh-TW is mostly fuzzy and uk-UA mostly untranslated; gettext skips both too.
        self::assertGreaterThan(10, min($counts));
        foreach (['ar-SA', 'de-DE', 'fr-FR', 'ja-JP', 'pl-PL', 'pt-BR', 'ru-RU', 'zh-CN'] as $locale) {
            self::assertGreaterThan(450, $counts[$locale], $locale);
        }
    }

    /** @return iterable<string, array{string, string, string, array<string, int|string>, string}> */
    public static function resolved(): iterable
    {
        yield 'French YAML wins over French gettext' => ['fr', 'inventory', 'Devices', [], 'Appareils'];
        yield 'German from gettext' => ['de_DE', 'inventory', 'Devices', [], 'Zielsysteme'];
        yield 'Russian from gettext' => ['ru_RU', 'inventory', 'Devices', [], 'Устройства'];
        yield 'Japanese from gettext' => ['ja_JP', 'inventory', 'Devices', [], 'デバイス'];
        yield 'Arabic from gettext' => ['ar_SA', 'inventory', 'Devices', [], 'الأجهزة'];
        yield 'missing msgid falls back to English' => ['de_DE', 'inventory', 'Save site', [], 'Save site'];
        yield 'missing msgid with placeholder falls back to English' => ['ru_RU', 'inventory', 'Page %page%', ['%page%' => 3], 'Page 3'];
        yield 'printf conversion becomes the Symfony placeholder' => ['de_DE', 'data_source_profile', 'Every %count% Hours', ['%count%' => 6], 'Alle 6 Stunden'];
        yield 'named placeholder through %s' => ['de_DE', 'graph_definition', 'VDEFs [edit: %name%]', ['%name%' => '<x>'], 'VDEFs [bearbeiten: <x>]'];
        yield 'space-flag conversion is not guessed' => ['he_IL', 'data_source_profile', '%count% Day', ['%count%' => 1], '1 Day'];
        yield 'plural form for n != 1 locale' => ['de_DE', 'palette', 'Delete Colors', [], 'Farben löschen'];
        yield 'single plural form locale' => ['ja_JP', 'palette', 'Delete Colors', [], '色を削除'];
        yield 'n > 1 plural rule keeps English' => ['pt_BR', 'palette', 'Delete Colors', [], 'Delete Colors'];
        yield 'inverted Hindi plural rule keeps English' => ['hi_IN', 'palette', 'Delete Colors', [], 'Delete Colors'];
        yield 'Russian plural entry keeps English' => ['ru_RU', 'palette', 'Delete Colors', [], 'Delete Colors'];
        yield 'Arabic plural entry keeps English' => ['ar_SA', 'palette', 'Delete Colors', [], 'Delete Colors'];
        yield 'Russian non-plural count message matches legacy __()' => ['ru_RU', 'data_source_profile', '%count% Days', ['%count%' => 5], '5 Дней'];
        yield 'unshipped locale stays English' => ['fi', 'inventory', 'Devices', [], 'Devices'];
    }

    /** @param array<string, int|string> $parameters */
    #[DataProvider('resolved')]
    public function testKernelTranslatorResolvesRealCatalogs(string $locale, string $domain, string $key, array $parameters, string $expected): void
    {
        self::assertSame($expected, self::translator()->trans($key, $parameters, $domain, $locale));
    }

    public function testKeyMissingFromLocaleYamlFallsBackToSameLanguageGettext(): void
    {
        $directory = sys_get_temp_dir() . '/kadupul-gettext-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            file_put_contents($directory . '/probe.en.yaml', "\"Devices\": \"Devices\"\n\"Notes\": \"Notes\"\n\"Cancel\": \"Cancel\"\n\"Not in gettext\": \"Not in gettext\"\n");
            file_put_contents($directory . '/probe.fr.yaml', "\"Devices\": \"Appareils\"\n");
            $resource = ['source' => $directory . '/probe.en.yaml', 'catalog' => self::ROOT . '/locales/po/fr-FR.po', 'domain' => $directory . '/probe.fr.yaml'];
            $catalogue = (new GettextCatalogLoader())->load($resource, 'fr', 'probe');
            self::assertSame(['Notes' => 'Notes', 'Cancel' => 'Annuler'], $catalogue->all('probe'));
            $resource['domain'] = $directory . '/absent.fr.yaml';
            self::assertSame('Équipements', (new GettextCatalogLoader())->load($resource, 'fr', 'probe')->get('Devices', 'probe'));
        } finally {
            array_map('unlink', glob($directory . '/*') ?: []);
            rmdir($directory);
        }
    }

    public function testInvalidResourceIsRefused(): void
    {
        $this->expectException(\Symfony\Component\Translation\Exception\InvalidResourceException::class);
        (new GettextCatalogLoader())->load(self::ROOT . '/locales/po/de-DE.po', 'de_DE', 'inventory');
    }

    public function testAmbiguousOrUnsafeEntriesStayUntranslated(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kadupul-po-');
        self::assertIsString($path);
        try {
            file_put_contents($path, <<<'PO'
                msgid ""
                msgstr ""
                "Content-Type: text/plain; charset=UTF-8\n"
                "Plural-Forms: nplurals=2; plural=(n != 1);\n"

                msgid "Delete"
                msgstr "Löschen"

                msgctxt "dropdown action"
                msgid "Delete"
                msgstr "Entfernen"

                msgid "Copy %s to %s"
                msgstr "%2$s aus %1$s kopieren"

                msgid "Mixed %s and %s"
                msgstr "%2$s und %s"

                msgid "Load %d"
                msgstr "Last %d%%"

                msgid "Width %d"
                msgstr "Breite %5d"

                msgid "Extra %s"
                msgstr "%s %s"

                msgid "Pipe"
                msgstr "a|b"

                msgid "Untranslated"
                msgstr ""

                #, fuzzy
                msgid "Fuzzy"
                msgstr "Unscharf"

                msgid "Color"
                msgid_plural "Colors"
                msgstr[0] "Farbe"
                msgstr[1] "Farben"

                msgid "Item"
                msgid_plural "Items"
                msgstr[0] "Element"
                msgstr[1] ""

                PO);
            $catalog = GettextCatalog::fromFile($path);
            self::assertNull($catalog->translate('Delete'));
            self::assertSame('%to% aus %from% kopieren', $catalog->translate('Copy %from% to %to%'));
            self::assertNull($catalog->translate('Mixed %a% and %b%'));
            self::assertSame('Last %load%%', $catalog->translate('Load %load%'));
            self::assertNull($catalog->translate('Load %load%%'));
            self::assertNull($catalog->translate('Width %width%'));
            self::assertNull($catalog->translate('Extra %value%'));
            self::assertNull($catalog->translate('Pipe'));
            self::assertNull($catalog->translate('Untranslated'));
            self::assertNull($catalog->translate('Fuzzy'));
            self::assertSame('Farbe', $catalog->translate('Color'));
            self::assertSame('Farben', $catalog->translate('Colors'));
            self::assertNull($catalog->translate('Items'));
            self::assertNull($catalog->translate('100%'));
            self::assertNull($catalog->translate('Absent'));
        } finally {
            unlink($path);
        }
    }

    /**
     * Symfony picks plural forms with its own CLDR-style table, gettext with the
     * catalog's Plural-Forms header. This records exactly where they disagree.
     */
    public function testSymfonyPluralSelectionAgainstShippedPluralForms(): void
    {
        $identity = new \Symfony\Component\Translation\IdentityTranslator();
        $differing = [];
        foreach (self::shippedLocales() as $locale) {
            $header = (string) file_get_contents(self::ROOT . '/locales/po/' . $locale . '.po');
            self::assertSame(1, preg_match('/^"Plural-Forms: nplurals=(\d+); plural=([^;"]+);\\\\n"$/m', $header, $match), $locale);
            $symfonyLocale = $locale === 'fr-FR' ? 'fr' : str_replace('-', '_', $locale);
            $choices = implode('|', range(0, 5));
            for ($n = 0; $n <= 1000; $n++) {
                $gettext = self::evaluate($match[2], $n);
                self::assertLessThan((int) $match[1], $gettext, $locale);
                if ((int) $identity->trans($choices, ['%count%' => $n], null, $symfonyLocale) !== $gettext) {
                    $differing[$locale] = $n;
                    break;
                }
            }
        }
        // First n where they disagree: he-IL has four gettext forms (0 is form 3)
        // and two in Symfony; hi-IN's header puts the plural in form 0; lv-LV treats 0, 10
        // and 11-19 differently; tr-TR has one Symfony form and two gettext forms.
        self::assertSame(['he-IL' => 0, 'hi-IN' => 0, 'lv-LV' => 10, 'tr-TR' => 0], $differing);
    }

    public function testRussianAndArabicPluralFormsMatchGettextThroughSymfony(): void
    {
        $identity = new \Symfony\Component\Translation\IdentityTranslator();
        $rule = 'n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2';
        $checked = 0;
        foreach ((new PoFileLoader())->load(self::ROOT . '/locales/po/ru-RU.po', 'ru_RU')->all('messages') as $id => $message) {
            $parts = explode('|', $message);
            if (substr_count((string) $id, '|') !== 1 || count($parts) !== 3 || in_array('', $parts, true) || count(array_unique($parts)) < 2 || preg_grep('/^\s*(\w+:|[\[\]{])/', $parts) !== []) {
                continue;
            }
            foreach ([0, 1, 2, 3, 5, 11, 12, 21, 22, 25, 101, 102, 111, 112, 1000] as $n) {
                self::assertSame(trim($parts[self::evaluate($rule, $n)]), $identity->trans($message, ['%count%' => $n], null, 'ru_RU'), $id . ' ' . $n);
            }
            $checked++;
        }
        self::assertGreaterThan(0, $checked);
        // Arabic selects the same form index as gettext (see the table above), but
        // no shipped Arabic plural entry has all six forms, so none is bridged.
        $arabic = array_filter((new PoFileLoader())->load(self::ROOT . '/locales/po/ar-SA.po', 'ar_SA')->all('messages'), static fn(string $message, string|int $id): bool => substr_count((string) $id, '|') === 1, ARRAY_FILTER_USE_BOTH);
        self::assertNotEmpty($arabic);
        foreach ($arabic as $message) {
            $parts = explode('|', $message);
            self::assertTrue(count($parts) !== 6 || in_array('', $parts, true));
        }
        self::assertSame(['Delete Colors' => 'Delete Colors'], [self::translator()->trans('Delete Colors', [], 'palette', 'ar_SA') => self::translator()->trans('Delete Colors', ['%count%' => 3], 'palette', 'ar_SA')]);
    }

    /** @return iterable<string, array{mixed, ?string}> */
    public static function spellings(): iterable
    {
        yield 'English region' => ['en-GB', 'en'];
        yield 'legacy French' => ['fr_FR', 'fr'];
        yield 'French region' => ['fr-CA', 'fr'];
        yield 'Brazilian hyphen' => ['pt-BR', 'pt_BR'];
        yield 'Brazilian underscore lower case' => ['pt_br', 'pt_BR'];
        yield 'Portugal' => ['pt-PT', 'pt_PT'];
        yield 'bare Portuguese follows legacy list order' => ['pt', 'pt_PT'];
        yield 'unshipped Portuguese region' => ['pt-AO', null];
        yield 'simplified Chinese' => ['zh-CN', 'zh_CN'];
        yield 'traditional Chinese' => ['zh_TW', 'zh_TW'];
        yield 'bare Chinese follows legacy list order' => ['zh', 'zh_CN'];
        yield 'unshipped Chinese region' => ['zh-HK', null];
        yield 'German region' => ['de-AT', 'de_DE'];
        yield 'bare upper-case German' => ['DE', 'de_DE'];
        yield 'Japanese' => ['ja', 'ja_JP'];
        yield 'Russian' => ['ru-RU', 'ru_RU'];
        yield 'Arabic' => ['ar', 'ar_SA'];
        yield 'unshipped language' => ['fi-FI', null];
        yield 'legacy-listed unshipped language' => ['sr-RS', null];
        yield 'script subtag' => ['zh-Hant-TW', null];
        yield 'charset suffix' => ['de_DE.UTF-8', null];
        yield 'path' => ['../../locales/po/ru-RU', null];
        yield 'absolute path' => ['/etc/passwd', null];
        yield 'trailing newline' => ["de\n", null];
        yield 'NUL' => ["de\0", null];
        yield 'array' => [['de'], null];
    }

    #[DataProvider('spellings')]
    public function testLocaleSpellingNormalization(mixed $spelling, ?string $expected): void
    {
        self::assertSame($expected, ConsoleTranslations::normalize($spelling, self::ROOT));
    }

    /** @return iterable<string, array{?string, ?string, string, array<string, string>, string}> */
    public static function requests(): iterable
    {
        yield 'saved Russian preference' => [null, 'ru-RU', 'de', [], 'ru_RU'];
        yield 'forced Japanese' => ['ja-JP', 'ru-RU', 'de', [], 'ja_JP'];
        yield 'weighted browser picks Polish' => [null, null, 'xx;q=1,pl-PL;q=0.8,de;q=0.5', [], 'pl_PL'];
        yield 'browser path cannot select a catalog' => [null, null, '../../locales/po/ru-RU,../../../etc/passwd', ['i18n_default_language' => 'de-DE'], 'de_DE'];
        yield 'preference path cannot select a catalog' => [null, '../locales/po/ru-RU.po', 'zz', [], 'en'];
        yield 'default language accepts legacy spelling' => [null, null, 'zz', ['i18n_default_language' => 'zh_TW'], 'zh_TW'];
        yield 'disabled support still forces English' => ['ja-JP', 'ru-RU', 'de', ['i18n_language_support' => '0'], 'en'];
    }

    /** @param array<string, string> $settings */
    #[DataProvider('requests')]
    public function testRequestValuesSelectOnlyShippedCatalogs(?string $forced, ?string $user, string $browser, array $settings, string $expected): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
        foreach ($settings as $name => $value) {
            $pdo->prepare('INSERT INTO settings VALUES (?, ?)')->execute([$name, $value]);
        }
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $preference = $this->createMock(LocalePreference::class);
        $preference->method('preferredLocale')->willReturn($user);
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['forced_locale' => $forced]);
        $subscriber = new InventoryLocaleSubscriber($preference, $database, $configuration, self::ROOT);
        $request = Request::create('/inventory/sites', 'GET', ['language' => '../../locales/po/ar-SA', '_locale' => 'ar_SA'], ['Cacti' => 'fixture', 'language' => 'ar-SA', '_locale' => 'ar_SA']);
        $request->attributes->set('_route', 'inventory_sites');
        $request->headers->set('Accept-Language', $browser);
        $subscriber->onRequest(new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertSame($expected, $request->attributes->get('_locale'));
    }

    public function testGermanSitePageRendersFromGettextWithValidLanguageTag(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $configuration = $this->createMock(LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['forced_locale' => 'de-DE']);
            $container->set(LegacyConfiguration::class, $configuration);
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
            $database = $this->createMock(DatabaseConnection::class);
            $database->method('get')->willReturn($pdo);
            $container->set(DatabaseConnection::class, $database);
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
            $access->method('canManageDevices')->willReturn(true);
            $container->set(ConsoleAccess::class, $access);
            $editor = $this->createMock(SiteEditor::class);
            $editor->method('find')->willReturn(new Site(12, '<script>site</script>', ''));
            $container->set(SiteEditor::class, $editor);
            $response = $kernel->handle(Request::create('/inventory/sites/12/edit', 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $response->getStatusCode());
            $content = (string) $response->getContent();
            self::assertStringContainsString('<html lang="de-DE">', $content);
            self::assertStringContainsString('>Notizen</label>', $content);
            self::assertStringContainsString('Save site', $content);
            self::assertStringContainsString('&lt;script&gt;site&lt;/script&gt;', $content);
        } finally {
            $kernel->shutdown();
        }
    }

    /** Evaluates a gettext Plural-Forms expression (C operators, n only) for the comparison above. */
    private static function evaluate(string $expression, int $n): int
    {
        preg_match_all('/\s*(\d+|n|&&|\|\||==|!=|<=|>=|[<>?:()%!*\/+-])/', $expression, $matches);
        self::assertSame(preg_replace('/\s+/', '', $expression), implode('', $matches[1]));
        $tokens = $matches[1];
        $position = 0;
        $peek = static function () use (&$tokens, &$position): ?string {
            return $tokens[$position] ?? null;
        };
        $binary = [['||'], ['&&'], ['==', '!='], ['<', '>', '<=', '>='], ['+', '-'], ['*', '/', '%']];
        $parse = null;
        $level = null;
        $unary = static function () use (&$tokens, &$position, &$parse, $n, &$unary): int {
            $token = $tokens[$position++];
            if ($token === '!') {
                return (int) !$unary();
            }
            if ($token === '(') {
                $value = $parse();
                $position++;

                return $value;
            }

            return $token === 'n' ? $n : (int) $token;
        };
        $level = static function (int $depth) use (&$level, $binary, $peek, &$position, $unary): int {
            if ($depth === count($binary)) {
                return $unary();
            }
            $value = $level($depth + 1);
            while (in_array($peek(), $binary[$depth], true)) {
                $operator = $peek();
                $position++;
                $right = $level($depth + 1);
                $value = match ($operator) {
                    '||' => (int) ($value || $right), '&&' => (int) ($value && $right),
                    '==' => (int) ($value === $right), '!=' => (int) ($value !== $right),
                    '<' => (int) ($value < $right), '>' => (int) ($value > $right),
                    '<=' => (int) ($value <= $right), '>=' => (int) ($value >= $right),
                    '+' => $value + $right, '-' => $value - $right, '*' => $value * $right,
                    '/' => intdiv($value, $right), '%' => $value % $right,
                };
            }

            return $value;
        };
        $parse = static function () use (&$parse, $level, $peek, &$position): int {
            $condition = $level(0);
            if ($peek() !== '?') {
                return $condition;
            }
            $position++;
            $then = $parse();
            $position++;
            $else = $parse();

            return $condition !== 0 ? $then : $else;
        };
        $value = $parse();
        self::assertSame(count($tokens), $position);

        return $value;
    }
}
