<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\LocalePreference;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Infrastructure\Symfony\LocaleSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Characterizes locale selection in the real include/global_languages.php.
 *
 * Each case runs the shipped file in a child process, as include/global.php
 * includes it, with the shipped catalogs and php-gettext handler. The
 * expectations were recorded against origin/main before the shared resolver
 * existed. Every case still holds except the Accept-Language cases marked
 * "weighted", the one intentional change: the header is now an RFC 9110
 * weighted list, and its first range by weight that names a locale with a
 * catalog wins. Each of those cases notes what the first-entry rule chose.
 */
final class LegacyLocaleResolutionTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private static ?string $base = null;

    public static function setUpBeforeClass(): void
    {
        $base = sys_get_temp_dir() . '/kadupul-legacy-locale-' . bin2hex(random_bytes(8));
        mkdir($base . '/plugins/withgerman/locales/LC_MESSAGES', 0700, true);
        mkdir($base . '/plugins/nocatalog', 0700, true);
        symlink(realpath(self::ROOT . '/locales'), $base . '/locales');
        copy(self::ROOT . '/locales/LC_MESSAGES/de-DE.mo', $base . '/plugins/withgerman/locales/LC_MESSAGES/de-DE.mo');
        self::$base = $base;
    }

    public static function tearDownAfterClass(): void
    {
        $base = self::$base;
        self::$base = null;
        if ($base === null) {
            return;
        }
        unlink($base . '/plugins/withgerman/locales/LC_MESSAGES/de-DE.mo');
        rmdir($base . '/plugins/withgerman/locales/LC_MESSAGES');
        rmdir($base . '/plugins/withgerman/locales');
        rmdir($base . '/plugins/withgerman');
        rmdir($base . '/plugins/nocatalog');
        rmdir($base . '/plugins');
        unlink($base . '/locales');
        rmdir($base);
    }

    /**
     * @param array<string, mixed> $fixture
     * @param array<string, mixed> $expected
     */
    #[DataProvider('scenarios')]
    public function testLegacyBootstrapSelectsTheCharacterizedLocale(array $fixture, array $expected): void
    {
        $result = self::bootstrap($fixture);
        foreach ($expected as $key => $value) {
            self::assertArrayHasKey($key, $result);
            self::assertSame($value, $result[$key], $key);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function scenarios(): iterable
    {
        $english = ['CACTI_LOCALE' => 'en-US', 'CACTI_COUNTRY' => 'us', 'CACTI_LANGUAGE' => 'English', 'text' => 'Devices'];
        $german = ['CACTI_LOCALE' => 'de-DE', 'CACTI_COUNTRY' => 'de', 'CACTI_LANGUAGE' => 'German', 'cacti_locale' => 'de-DE', 'text' => 'Zielsysteme'];
        $french = ['CACTI_LOCALE' => 'fr-FR', 'CACTI_COUNTRY' => 'fr', 'CACTI_LANGUAGE' => 'French', 'cacti_locale' => 'fr-FR', 'text' => 'Équipements'];
        $user = ['sess_user_id' => 7];

        yield 'nothing configured uses English and clears the session language' => [
            [], $english + ['cacti_locale' => 'en-US', 'session_language' => '', 'user_writes' => [], 'domains' => []],
        ];
        yield 'disabled support ignores a forced language and the session' => [
            ['settings' => ['i18n_language_support' => '0'], 'forced' => 'de-DE', 'session' => ['sess_user_language' => 'fr-FR']],
            $english + ['cacti_locale' => 'en-US', 'session_language' => '', 'request_language' => null, 'user_writes' => []],
        ];
        yield 'forced language beats the request and session and is written as language' => [
            ['forced' => 'de-DE', 'request' => ['language' => 'fr-FR'], 'session' => ['sess_user_language' => 'fr-FR', 'sess_current_date1' => 'a', 'sess_current_date2' => 'b']],
            $german + ['session_language' => 'de-DE', 'request_language' => 'de-DE', 'user_writes' => [['language', 'de-DE']], 'session_dates_kept' => false],
        ];
        yield 'forced underscore spelling is repaired' => [
            ['forced' => 'de_DE'],
            $german + ['request_language' => 'de-DE', 'user_writes' => [['language', 'de-DE']]],
        ];
        yield 'empty forced language is ignored' => [
            ['forced' => '', 'session' => ['sess_user_language' => 'fr-FR']],
            $french + ['request_language' => null, 'user_writes' => []],
        ];
        yield 'request language beats the session, clears the dates and writes language' => [
            ['request' => ['language' => 'fr-FR'], 'session' => ['sess_user_language' => 'de-DE', 'sess_current_date1' => 'a', 'sess_current_date2' => 'b']],
            $french + ['session_language' => 'fr-FR', 'request_language' => 'fr-FR', 'user_writes' => [['language', 'fr-FR']], 'session_dates_kept' => false],
        ];
        yield 'bare request language picks the first listed region' => [
            ['request' => ['language' => 'fr']],
            $french + ['request_language' => 'fr-FR', 'user_writes' => [['language', 'fr-FR']]],
        ];
        yield 'unknown request language is blanked and the session applies' => [
            ['request' => ['language' => 'xx-YY'], 'session' => ['sess_user_language' => 'de-DE', 'sess_current_date1' => 'a', 'sess_current_date2' => 'b']],
            $german + ['request_language' => '', 'user_writes' => [], 'session_dates_kept' => true],
        ];
        yield 'request language without a catalog is written and then falls back' => [
            ['request' => ['language' => 'zh-HK']],
            $english + ['cacti_locale' => 'zh-HK', 'cacti_country' => 'hk', 'session_language' => '', 'request_language' => 'zh-HK', 'user_writes' => [['language', 'zh-HK']]],
        ];
        yield 'session language beats the saved language' => [
            ['session' => $user + ['sess_user_language' => 'de-DE', 'sess_current_date1' => 'a', 'sess_current_date2' => 'b'], 'saved' => [7 => ['user_language' => 'ru-RU']]],
            $german + ['session_language' => 'de-DE', 'user_writes' => [], 'session_dates_kept' => true],
        ];
        yield 'session language must be an exact locale' => [
            ['session' => $user + ['sess_user_language' => 'de'], 'saved' => [7 => ['user_language' => 'fr-FR']]],
            $french + ['session_language' => 'fr-FR'],
        ];
        yield 'saved language is read for the session user' => [
            ['session' => $user, 'saved' => [7 => ['user_language' => 'ru_RU']]],
            ['CACTI_LOCALE' => 'ru-RU', 'cacti_locale' => 'ru-RU', 'session_language' => 'ru-RU', 'text' => 'Устройства', 'user_writes' => []],
        ];
        yield 'saved language of another user is not read' => [
            ['session' => $user, 'saved' => [8 => ['user_language' => 'ru-RU']], 'accept' => 'de-DE'],
            $german,
        ];
        yield 'saved pt-AO uses the first listed Portuguese' => [
            ['session' => $user, 'saved' => [7 => ['user_language' => 'pt-AO']]],
            ['CACTI_LOCALE' => 'pt-PT', 'cacti_locale' => 'pt-PT', 'session_language' => 'pt-PT'],
        ];
        yield 'saved zh-HK is listed but has no catalog, so English' => [
            ['session' => $user, 'saved' => [7 => ['user_language' => 'zh-HK']], 'accept' => 'de-DE'],
            $english + ['cacti_locale' => 'zh-HK', 'cacti_country' => 'hk', 'session_language' => ''],
        ];
        yield 'saved en-GB is listed English without a catalog' => [
            ['session' => $user, 'saved' => [7 => ['user_language' => 'en-GB']], 'accept' => 'de-DE'],
            $english + ['cacti_locale' => 'en-GB', 'session_language' => ''],
        ];
        yield 'saved fr-CA collapses to fr-FR' => [
            ['session' => $user, 'saved' => [7 => ['user_language' => 'fr-CA']]],
            $french,
        ];
        yield 'browser first entry' => [
            ['accept' => 'de-DE,fr;q=0.8'],
            $german + ['session_language' => 'de-DE', 'user_writes' => []],
        ];
        yield 'browser region picks the shipped region' => [
            ['accept' => 'de-AT'],
            $german,
        ];
        yield 'weighted: the first entry is shipped and keeps the first-entry result' => [
            ['accept' => 'de-DE,xx;q=0.9,fr;q=0.8'],
            $german + ['session_language' => 'de-DE'],
        ];
        yield 'weighted: the weight is not part of the language' => [
            ['accept' => 'pt-BR;q=0.9'],
            // First-entry rule: 'pt-BR;q=0.9' is no locale, so its first two
            // characters picked the first listed Portuguese, pt-PT.
            ['CACTI_LOCALE' => 'pt-BR', 'cacti_locale' => 'pt-BR'],
        ];
        yield 'weighted: only a later entry is shipped' => [
            ['accept' => 'xx-YY,de-DE;q=0.8', 'settings' => ['i18n_default_language' => 'fr-FR']],
            // First-entry rule: xx-YY is unknown, so the default, fr-FR.
            $german + ['session_language' => 'de-DE'],
        ];
        yield 'weighted: the first entry is listed without a catalog' => [
            ['accept' => 'fi-FI,de;q=0.5'],
            // First-entry rule: fi-FI, which has no catalog, so English.
            $german,
        ];
        yield 'weighted: a later entry carries the higher weight' => [
            ['accept' => 'de;q=0.5,fr'],
            // First-entry rule: de-DE.
            $french,
        ];
        yield 'weighted: no entry is shipped, so the default' => [
            ['accept' => 'fi-FI,xx;q=0.5', 'settings' => ['i18n_default_language' => 'fr-FR']],
            // First-entry rule: fi-FI, which has no catalog, so English.
            $french,
        ];
        yield 'browser English region is English' => [
            ['accept' => 'en-GB,de;q=0.5'],
            $english + ['cacti_locale' => 'en-GB', 'session_language' => ''],
        ];
        yield 'weighted: an empty header names no language, so the default' => [
            ['accept' => '', 'settings' => ['i18n_default_language' => 'fr-FR']],
            // First-entry rule: the empty entry was repaired to en-US.
            $french,
        ];
        yield 'auto-detection off uses the default' => [
            ['accept' => 'de-DE', 'settings' => ['i18n_auto_detection' => '0', 'i18n_default_language' => 'fr-FR']],
            $french,
        ];
        yield 'auto-detection on uses the browser' => [
            ['accept' => 'de-DE', 'settings' => ['i18n_auto_detection' => '1', 'i18n_default_language' => 'fr-FR']],
            $german,
        ];
        yield 'default language spelling is repaired' => [
            ['settings' => ['i18n_default_language' => 'ja']],
            ['CACTI_LOCALE' => 'ja-JP', 'cacti_locale' => 'ja-JP', 'session_language' => 'ja-JP'],
        ];
        yield 'unknown default language uses the setting default' => [
            ['settings' => ['i18n_default_language' => 'xx'], 'defaults' => ['i18n_default_language' => 'de-DE']],
            $german,
        ];
        yield 'unknown default language without a setting default is English' => [
            ['settings' => ['i18n_default_language' => 'xx']],
            $english + ['cacti_locale' => 'en-US'],
        ];
        yield 'strict mode falls back when a plugin lacks the language' => [
            ['settings' => ['i18n_language_support' => '2'], 'accept' => 'de-DE', 'plugins' => ['withgerman', 'nocatalog']],
            $english + ['cacti_locale' => 'de-DE', 'session_language' => '', 'domains' => []],
        ];
        yield 'strict mode keeps the language when every plugin has it' => [
            ['settings' => ['i18n_language_support' => '2'], 'accept' => 'de-DE', 'plugins' => ['withgerman']],
            $german + ['session_language' => 'de-DE', 'domains' => ['cacti', 'withgerman']],
        ];
        yield 'normal support ignores a plugin without the language' => [
            ['settings' => ['i18n_language_support' => '1'], 'accept' => 'de-DE', 'plugins' => ['withgerman', 'nocatalog']],
            $german + ['domains' => ['cacti', 'withgerman']],
        ];
        yield 'installer apply_locale repairs and sets the globals' => [
            ['apply' => 'ru'],
            ['apply' => 'ru-RU', 'apply_globals' => ['ru-RU', 'ru']],
        ];
        yield 'installer apply_locale of an unknown language falls through to the browser' => [
            ['apply' => 'xx', 'accept' => 'de-AT'],
            ['apply' => 'de-DE', 'apply_globals' => ['de-DE', 'de']],
        ];
    }

    /**
     * The same settings, forced language, saved preference, Accept-Language
     * and plugins reach a Symfony route through LocaleSubscriber, which must
     * show the language the legacy page shows.
     *
     * @param array<string, mixed> $fixture
     */
    #[DataProvider('sharedSources')]
    public function testSymfonyRoutesShowTheLegacyLanguage(array $fixture, string $symfony): void
    {
        $legacy = self::bootstrap($fixture)['CACTI_LOCALE'];
        self::assertSame($symfony, match ($legacy) {
            'en-US' => 'en',
            'fr-FR' => 'fr',
            default => str_replace('-', '_', $legacy),
        });

        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT); CREATE TABLE plugin_config (id INTEGER PRIMARY KEY, directory TEXT)');
        foreach ($fixture['settings'] ?? [] as $name => $value) {
            $pdo->prepare('INSERT INTO settings VALUES (?, ?)')->execute([$name, $value]);
        }
        foreach ($fixture['plugins'] ?? [] as $directory) {
            $pdo->prepare('INSERT INTO plugin_config (directory) VALUES (?)')->execute([$directory]);
        }
        $database = $this->createStub(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $preference = $this->createStub(LocalePreference::class);
        $preference->method('preferredLocale')->willReturn($fixture['saved'][7]['user_language'] ?? null);
        $configuration = $this->createStub(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['forced_locale' => $fixture['forced'] ?? null]);
        $request = Request::create('/inventory/sites', 'GET', [], ['Cacti' => 'fixture']);
        $request->attributes->set('_route', 'inventory_sites');
        if (array_key_exists('accept', $fixture)) {
            $request->headers->set('Accept-Language', $fixture['accept']);
        }
        (new LocaleSubscriber($preference, $database, $configuration, (string) self::$base))->onRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));

        self::assertSame($symfony, $request->attributes->get('_locale'));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function sharedSources(): iterable
    {
        $user = ['sess_user_id' => 7];

        yield 'nothing configured' => [[], 'en'];
        yield 'disabled support' => [['settings' => ['i18n_language_support' => '0'], 'forced' => 'de-DE'], 'en'];
        yield 'forced language' => [['forced' => 'ru_RU', 'accept' => 'de'], 'ru_RU'];
        yield 'forced language without a catalog' => [['forced' => 'zh-HK', 'accept' => 'de'], 'en'];
        yield 'saved language' => [['session' => $user, 'saved' => [7 => ['user_language' => 'fr-CA']], 'accept' => 'de'], 'fr'];
        yield 'saved pt-AO' => [['session' => $user, 'saved' => [7 => ['user_language' => 'pt-AO']]], 'pt_PT'];
        yield 'saved zh-HK' => [['session' => $user, 'saved' => [7 => ['user_language' => 'zh-HK']], 'accept' => 'de-DE'], 'en'];
        yield 'saved en-GB' => [['session' => $user, 'saved' => [7 => ['user_language' => 'en-GB']], 'accept' => 'de-DE'], 'en'];
        yield 'browser first entry is shipped' => [['accept' => 'de-DE,fr;q=0.8'], 'de_DE'];
        yield 'browser: only a later entry is shipped' => [['accept' => 'xx-YY,de-DE;q=0.8', 'settings' => ['i18n_default_language' => 'fr-FR']], 'de_DE'];
        yield 'browser: first entry is listed without a catalog' => [['accept' => 'fi-FI,de;q=0.5'], 'de_DE'];
        yield 'browser script subtag' => [['accept' => 'zh-Hant-TW'], 'zh_CN'];
        yield 'empty browser header' => [['accept' => '', 'settings' => ['i18n_default_language' => 'ja-JP']], 'ja_JP'];
        yield 'detection off' => [['accept' => 'de-DE', 'settings' => ['i18n_auto_detection' => '0', 'i18n_default_language' => 'fr-FR']], 'fr'];
        yield 'strict mode, a plugin lacks the language' => [['settings' => ['i18n_language_support' => '2'], 'accept' => 'de-DE', 'plugins' => ['withgerman', 'nocatalog']], 'en'];
        yield 'strict mode, every plugin has the language' => [['settings' => ['i18n_language_support' => '2'], 'accept' => 'de-DE', 'plugins' => ['withgerman']], 'de_DE'];
        yield 'strict mode without plugins' => [['settings' => ['i18n_language_support' => '2'], 'accept' => 'ru'], 'ru_RU'];
    }

    /**
     * @param array<string, mixed> $fixture
     * @return array<string, mixed>
     */
    private static function bootstrap(array $fixture): array
    {
        self::assertNotNull(self::$base);
        $command = [PHP_BINARY, '-d', 'error_reporting=' . (E_ALL & ~E_DEPRECATED), self::ROOT . '/tests/Fixtures/legacy-locale-native.php', realpath(self::ROOT), json_encode($fixture, JSON_THROW_ON_ERROR), self::$base];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stdout . $stderr);
        self::assertSame('', $stderr);
        $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($result);

        return $result;
    }
}
