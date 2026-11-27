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
use Kadupul\Platform\Infrastructure\Symfony\LocaleSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ConsoleTranslationsTest extends TestCase
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

    /** @return iterable<string, array{string, string, string, array<string, int|string>, string}> */
    public static function resolved(): iterable
    {
        yield 'French YAML' => ['fr', 'inventory', 'Devices', [], 'Appareils'];
        yield 'German from YAML' => ['de_DE', 'inventory', 'Devices', [], 'Geräte'];
        yield 'Russian from YAML' => ['ru_RU', 'inventory', 'Devices', [], 'Устройства'];
        yield 'Japanese from YAML' => ['ja_JP', 'inventory', 'Devices', [], 'デバイス'];
        yield 'Arabic from YAML' => ['ar_SA', 'inventory', 'Devices', [], 'الأجهزة'];
        yield 'German YAML translates Save site' => ['de_DE', 'inventory', 'Save site', [], 'Standort speichern'];
        yield 'Russian YAML translates Page with placeholder' => ['ru_RU', 'inventory', 'Page %page%', ['%page%' => 3], 'Страница 3'];
        yield 'German YAML count placeholder' => ['de_DE', 'data_source_profile', 'Every %count% Hours', ['%count%' => 6], 'Alle 6 Stunden'];
        yield 'German YAML named placeholder' => ['de_DE', 'graph_definition', 'VDEFs [edit: %name%]', ['%name%' => '<x>'], 'VDEFs [Bearbeiten: <x>]'];
        yield 'Hebrew ICU plural message' => ['he_IL', 'data_source_profile', '{count, plural, one {{number} Day} other {{number} Days}}', ['count' => 1, 'number' => '1'], '1 יום'];
        yield 'German YAML palette action' => ['de_DE', 'palette', 'Delete Colors', [], 'Farben löschen'];
        yield 'Japanese YAML palette action' => ['ja_JP', 'palette', 'Delete Colors', [], '色を削除'];
        yield 'Brazilian Portuguese YAML palette action' => ['pt_BR', 'palette', 'Delete Colors', [], 'Excluir cores'];
        yield 'Hindi YAML palette action' => ['hi_IN', 'palette', 'Delete Colors', [], 'रंग हटाएं'];
        yield 'Russian YAML palette action' => ['ru_RU', 'palette', 'Delete Colors', [], 'Удалить цвета'];
        yield 'Arabic YAML palette action' => ['ar_SA', 'palette', 'Delete Colors', [], 'حذف الألوان'];
        yield 'Russian ICU plural message' => ['ru_RU', 'data_source_profile', '{count, plural, one {{number} Day} other {{number} Days}}', ['count' => 5, 'number' => '5'], '5 дн.'];
        yield 'Russian ICU plural message keeps today\'s form for 21' => ['ru_RU', 'data_source_profile', '{count, plural, one {{number} Day} other {{number} Days}}', ['count' => 21, 'number' => '21'], '21 дн.'];
        yield 'Canadian French keeps its own wording, not French' => ['fr_CA', 'inventory', 'Devices', [], 'Équipements'];
        yield 'Canadian French falls back to French' => ['fr_CA', 'inventory', 'Cancel', [], 'Annuler'];
        yield 'Canadian French placeholder' => ['fr_CA', 'data_source_profile', 'Every %count% Hours', ['%count%' => 6], 'Toutes les 6 heures'];
        yield 'Mexican Spanish partial file' => ['es_MX', 'inventory', 'Devices', [], 'Dispositivos'];
        yield 'Mexican Spanish YAML translates Save site' => ['es_MX', 'inventory', 'Save site', [], 'Guardar sitio'];
        yield 'Malagasy count placeholder' => ['mg_MG', 'data_source_profile', 'Every %count% Hours', ['%count%' => 6], 'Isaky ny 6 ora'];
        yield 'Egyptian Arabic' => ['ar_EG', 'inventory', 'Devices', [], 'الأجهزة'];
        yield 'Finnish from YAML' => ['fi_FI', 'inventory', 'Devices', [], 'Laitteet'];
        yield 'Filipino from YAML' => ['fil_PH', 'inventory', 'Devices', [], 'Mga Device'];
        yield 'British English falls back to English' => ['en_GB', 'inventory', 'Devices', [], 'Devices'];
        yield 'unshipped locale stays English' => ['is', 'inventory', 'Devices', [], 'Devices'];
    }

    /** @param array<string, int|string> $parameters */
    #[DataProvider('resolved')]
    public function testKernelTranslatorResolvesRealCatalogs(string $locale, string $domain, string $key, array $parameters, string $expected): void
    {
        self::assertSame($expected, self::translator()->trans($key, $parameters, $domain, $locale));
    }

    /**
     * Locales without a complete file set hold only the messages that differ
     * from their fallback (English, or French for fr_CA and fr_MA). They must
     * not invent keys, leave blanks or add placeholders.
     */
    public function testPartialLocaleFilesOnlyOverrideEnglishKeys(): void
    {
        $partial = 0;
        foreach (['ar_EG', 'ar_MA', 'en_GB', 'es_MX', 'fr_CA', 'fr_MA', 'mg_MG', 'rw_RW', 'so_SO'] as $locale) {
            foreach (glob(self::ROOT . '/config/translations/*.' . $locale . '.yaml') ?: [] as $file) {
                $domain = basename($file, '.' . $locale . '.yaml');
                $english = Yaml::parseFile(self::ROOT . '/config/translations/' . $domain . '.en.yaml');
                foreach (Yaml::parseFile($file) as $key => $message) {
                    $key = (string) $key;
                    self::assertArrayHasKey($key, $english, $file . ' [' . $key . ']');
                    self::assertNotSame('', trim((string) $message), $file . ' [' . $key . ']');
                    preg_match_all('/%[a-z_]+%/i', (string) $message, $found);
                    preg_match_all('/%[a-z_]+%/i', $key, $expected);
                    self::assertSame([], array_diff($found[0], $expected[0]), $file . ' [' . $key . ']');
                    ++$partial;
                }
            }
        }
        self::assertGreaterThan(6000, $partial);
    }

    /** @return iterable<string, array{mixed, ?string}> */
    public static function spellings(): iterable
    {
        yield 'British English' => ['en-GB', 'en_GB'];
        yield 'English region' => ['en-AU', 'en'];
        yield 'legacy French' => ['fr_FR', 'fr'];
        yield 'African French' => ['fr-SN', 'fr_SN'];
        yield 'Canadian French' => ['fr-CA', 'fr_CA'];
        yield 'Moroccan French' => ['fr-MA', 'fr_MA'];
        yield 'French region' => ['fr-BE', 'fr'];
        yield 'Mexican Spanish' => ['es-MX', 'es_MX'];
        yield 'unshipped Spanish region' => ['es-AR', null];
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
        yield 'Egyptian Arabic' => ['ar-EG', 'ar_EG'];
        yield 'Moroccan Arabic' => ['ar-MA', 'ar_MA'];
        yield 'unshipped Arabic region' => ['ar-AE', null];
        yield 'Malagasy' => ['mg-MG', 'mg_MG'];
        yield 'Kinyarwanda' => ['rw-RW', 'rw_RW'];
        yield 'Somali' => ['so-SO', 'so_SO'];
        yield 'Finnish' => ['fi-FI', 'fi_FI'];
        yield 'bare Czech' => ['cs', 'cs_CZ'];
        yield 'Norwegian Bokmål' => ['nb-NO', 'nb_NO'];
        yield 'Serbian' => ['sr-RS', 'sr_RS'];
        yield 'Filipino' => ['fil-PH', 'fil_PH'];
        yield 'bare Filipino' => ['fil', 'fil_PH'];
        yield 'unshipped language' => ['is-IS', null];
        yield 'legacy-listed unshipped language' => ['mk-MK', null];
        yield 'four-letter language' => ['fili-PH', null];
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
        $subscriber = new LocaleSubscriber($preference, $database, $configuration, self::ROOT);
        $request = Request::create('/inventory/sites', 'GET', ['language' => '../../locales/po/ar-SA', '_locale' => 'ar_SA'], ['Cacti' => 'fixture', 'language' => 'ar-SA', '_locale' => 'ar_SA']);
        $request->attributes->set('_route', 'inventory_sites');
        $request->headers->set('Accept-Language', $browser);
        $subscriber->onRequest(new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertSame($expected, $request->attributes->get('_locale'));
    }

    public function testGermanSitePageRendersFromYamlWithValidLanguageTag(): void
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
            self::assertStringContainsString('Standort speichern', $content);
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
