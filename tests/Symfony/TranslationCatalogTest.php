<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the migrated Symfony screens against untranslated user-visible text.
 *
 * Legacy pages keep gettext (include/global_languages.php); only code that has
 * been migrated to Symfony Translation is held to these rules.
 */
final class TranslationCatalogTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /**
     * Legacy-page helpers that render inside legacy gettext pages and have no
     * container to reach a Symfony translator. Counts pin the current calls so
     * a new gettext call in src/ fails this test.
     */
    private const GETTEXT_ALLOWLIST = [
        // Password-policy messages that auth_changepassword.php shows stay in the legacy gettext catalogs.
        'src/IdentityAccess/Infrastructure/Legacy/NativeLocalCredentials.php' => 1,
        'src/IdentityAccess/Infrastructure/Legacy/NativeLocalLoginPolicy.php' => 2,
        'src/IdentityAccess/Infrastructure/Legacy/NativePasswordComplexity.php' => 4,
        'src/IdentityAccess/Infrastructure/Legacy/PermissionFilter.php' => 7,
        // Installer adapters store the same translated defaults the legacy installer writes.
        'src/Platform/Infrastructure/Legacy/InstallerCollectorConfiguration.php' => 1,
        'src/Platform/Infrastructure/Legacy/InstallerToolPaths.php' => 1,
    ];

    private const GETTEXT_FUNCTIONS = ['__', '__esc', '_n', '__x', '_x', 'gettext', '_'];

    /** @var array<string, mixed>|null */
    private static ?array $manifest = null;

    /** @var list<string>|null */
    private static ?array $translatedLocales = null;

    /** @var list<string>|null */
    private static ?array $sparseYamlOverrides = null;

    /** @var array<string, list<string>>|null */
    private static ?array $scripts = null;

    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }
        $decoded = json_decode((string) file_get_contents(self::ROOT . '/locales/catalogs.json'), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return self::$manifest = $decoded;
    }

    private static function toSymfonyLocale(string $bcp47): string
    {
        // Symfony locale names: fr-FR is fr, others use an underscore.
        return $bcp47 === 'fr-FR' ? 'fr' : str_replace('-', '_', $bcp47);
    }

    /**
     * Full translated Symfony locales derived dynamically from locales/catalogs.json
     * (all translated_locales except sparse_yaml_overrides).
     *
     * @return list<string>
     */
    private static function translatedLocales(): array
    {
        if (self::$translatedLocales !== null) {
            return self::$translatedLocales;
        }
        $manifest = self::manifest();
        $declared = $manifest['translated_locales'] ?? null;
        $sparse = $manifest['sparse_yaml_overrides'] ?? [];
        self::assertIsArray($declared);
        self::assertIsArray($sparse);

        $locales = [];
        foreach ($declared as $locale) {
            self::assertIsString($locale);
            if (!in_array($locale, $sparse, true)) {
                $locales[] = self::toSymfonyLocale($locale);
            }
        }
        sort($locales);

        return self::$translatedLocales = $locales;
    }

    /**
     * Sparse YAML override Symfony locales derived dynamically from locales/catalogs.json.
     *
     * @return list<string>
     */
    private static function sparseYamlOverrides(): array
    {
        if (self::$sparseYamlOverrides !== null) {
            return self::$sparseYamlOverrides;
        }
        $sparse = self::manifest()['sparse_yaml_overrides'] ?? [];
        self::assertIsArray($sparse);

        $locales = [];
        foreach ($sparse as $locale) {
            self::assertIsString($locale);
            $locales[] = self::toSymfonyLocale($locale);
        }
        sort($locales);

        return self::$sparseYamlOverrides = $locales;
    }

    /**
     * Unicode scripts each Symfony locale is written in, derived from locales/catalogs.json.
     * Latin is accepted everywhere: product names, acronyms, units and code-like tokens stay as they are.
     *
     * @return array<string, list<string>>
     */
    private static function scripts(): array
    {
        if (self::$scripts !== null) {
            return self::$scripts;
        }
        $raw = self::manifest()['scripts'] ?? [];
        self::assertIsArray($raw);

        $map = [];
        foreach ($raw as $bcp47 => $scriptList) {
            self::assertIsString($bcp47);
            self::assertIsArray($scriptList);
            $validated = [];
            foreach ($scriptList as $script) {
                self::assertIsString($script);
                $validated[] = $script;
            }
            $map[self::toSymfonyLocale($bcp47)] = $validated;
        }

        return self::$scripts = $map;
    }

    /** @return iterable<string, array{string}> */
    public static function domains(): iterable
    {
        $files = glob(self::ROOT . '/config/translations/*.en.yaml') ?: [];
        self::assertNotSame([], $files, 'No translation catalogs were discovered.');
        foreach ($files as $file) {
            $domain = basename($file, '.en.yaml');
            yield $domain => [$domain];
        }
    }

    /** @var array<string, array<string, string>> */
    private static array $catalogs = [];

    /** @return array<string, string> */
    private static function catalog(string $domain, string $locale): array
    {
        $name = $domain . '.' . $locale;
        if (isset(self::$catalogs[$name])) {
            return self::$catalogs[$name];
        }
        $file = self::ROOT . '/config/translations/' . $name . '.yaml';
        self::assertFileExists($file);
        $messages = Yaml::parseFile($file);
        self::assertIsArray($messages);
        foreach ($messages as $id => $message) {
            self::assertIsString($message, $name . ': ' . $id);
        }

        return self::$catalogs[$name] = array_combine(array_map('strval', array_keys($messages)), $messages);
    }

    public function testManifestSingleSourceOfTruthMetadataIsValid(): void
    {
        $manifest = self::manifest();
        self::assertSame('en-US', $manifest['source_fallback'] ?? null);
        $declared = $manifest['translated_locales'] ?? null;
        $sparse = $manifest['sparse_yaml_overrides'] ?? null;
        $scripts = $manifest['scripts'] ?? null;
        self::assertIsArray($declared);
        self::assertIsArray($sparse);
        self::assertIsArray($scripts);
        self::assertSame(['en-GB'], $sparse);
        self::assertCount(count($declared) - count($sparse), self::translatedLocales());
        foreach ($sparse as $locale) {
            self::assertContains($locale, $declared);
        }
        foreach ($scripts as $locale => $scriptList) {
            self::assertContains($locale, $declared);
            self::assertIsArray($scriptList);
            self::assertNotSame([], $scriptList);
            foreach ($scriptList as $script) {
                self::assertIsString($script);
                self::assertSame(1, preg_match('/\A[A-Z][A-Za-z_]+\z/D', $script));
            }
        }
    }

    #[DataProvider('domains')]
    public function testEnglishAndTranslatedLocalesDefineTheSameMessages(string $domain): void
    {
        $english = self::catalog($domain, 'en');
        foreach (self::translatedLocales() as $locale) {
            $translated = self::catalog($domain, $locale);
            self::assertSame([], array_values(array_diff_key($english, $translated)), $domain . ' is missing ' . $locale . ' messages');
            self::assertSame([], array_values(array_diff_key($translated, $english)), $domain . ' has extra ' . $locale . ' messages');
        }
    }

    #[DataProvider('domains')]
    public function testMessagesAreNotBlankAndKeepPlaceholders(string $domain): void
    {
        $english = self::catalog($domain, 'en');
        foreach (self::translatedLocales() as $locale) {
            foreach (self::catalog($domain, $locale) as $id => $message) {
                $id = (string) $id;
                self::assertNotSame('', trim($message), $domain . ' ' . $locale . ': ' . $id);
                // A plural form may drop %count% ("Every %count% Day" becomes "Chaque jour") but must not invent one.
                self::assertSame([], array_diff(self::placeholders($message), self::placeholders($id)), $domain . ' ' . $locale . ': ' . $id);
                if (self::isIcuDomain($domain)) {
                    self::assertSame([], self::icuProblems($locale, $id, $message), $domain . ' ' . $locale . ': ' . $id);
                }
            }
        }
        foreach ($english as $id => $message) {
            self::assertNotSame('', trim($message), $domain . ' en: ' . $id);
            if (self::isIcuDomain($domain)) {
                self::assertSame([], self::icuProblems('en', $id, $message), $domain . ' en: ' . $id);
            }
        }
    }

    public function testOtherLocaleCatalogsAreShippedLocalesWithKnownMessages(): void
    {
        $shipped = array_merge(self::translatedLocales(), self::sparseYamlOverrides());
        $count = 0;
        foreach (glob(self::ROOT . '/config/translations/*.*.yaml') ?: [] as $file) {
            if (preg_match('/\A([a-z_]+(?:\+intl-icu)?)\.([A-Za-z_]+)\.yaml\z/D', basename($file), $match) !== 1) {
                self::fail('Unexpected translation file name: ' . basename($file));
            }
            [, $domain, $locale] = $match;
            if ($locale === 'en') {
                continue;
            }
            ++$count;
            self::assertContains($locale, $shipped, basename($file) . ' names no shipped locale');
            $english = self::catalog($domain, 'en');
            $messages = self::catalog($domain, $locale);
            self::assertSame([], array_values(array_diff(array_keys($messages), array_keys($english))), basename($file) . ' has messages English lacks');
            foreach ($messages as $id => $message) {
                $id = (string) $id;
                self::assertNotSame('', trim($message), basename($file) . ': ' . $id);
                self::assertSame([], array_diff(self::placeholders($message), self::placeholders($id)), basename($file) . ': ' . $id);
                if (self::isIcuDomain($domain)) {
                    self::assertSame([], self::icuProblems($locale, $id, $message), basename($file) . ': ' . $id);
                }
                self::assertSame(1, preg_match('/\A[^\p{Cc}]*\z/u', $message), basename($file) . ' has a control character: ' . $id);
            }
        }
        self::assertGreaterThan(0, $count, 'No other-locale catalogs were discovered.');
    }

    /** @return list<string> */
    private static function placeholders(string $message): array
    {
        preg_match_all('/%\w+%/', $message, $matches);

        return $matches[0];
    }

    /** `<domain>+intl-icu` holds ICU MessageFormat messages, which name their arguments in braces. */
    private static function isIcuDomain(string $domain): bool
    {
        return str_ends_with($domain, '+intl-icu');
    }

    /** @return list<string> the argument names of an ICU message, such as count and number */
    private static function icuArguments(string $message): array
    {
        preg_match_all('/\{\s*(\w+)\s*[,}]/', $message, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Reasons an ICU message cannot be used for the key: it must parse for the
     * locale, add no argument the key lacks, keep the plural on `count`, and
     * format without error for counts in every plural category.
     *
     * @return list<string>
     */
    private static function icuProblems(string $locale, string $id, string $message): array
    {
        $problems = [];
        $arguments = array_diff(self::icuArguments($message), self::icuArguments($id));
        if ($arguments !== []) {
            $problems[] = 'adds the argument ' . implode(', ', $arguments) . ' the key lacks';
        }
        if (preg_match('/\{\s*count\s*,\s*plural\s*,/', $message) !== 1) {
            $problems[] = 'does not select its form with {count, plural, ...}';
        }
        $formatter = @\MessageFormatter::create($locale, $message);
        if ($formatter === null) {
            return [...$problems, 'is not a valid ICU message for ' . $locale . ': ' . intl_get_error_message()];
        }
        foreach ([0, 1, 2, 3, 5, 11, 21, 22, 25, 100, 101, 1.5] as $count) {
            $text = $formatter->format(['count' => $count, 'number' => (string) $count]);
            if ($text === false || trim($text) === '') {
                $problems[] = 'cannot be formatted for count ' . $count . ': ' . $formatter->getErrorMessage();
            }
        }

        return $problems;
    }

    public function testIcuProblemsAreDetected(): void
    {
        $key = '{count, plural, one {{number} Day} other {{number} Days}}';

        self::assertSame([], self::icuProblems('pl_PL', $key, '{count, plural, =1 {{number} dzień} few {{number} dni} other {{number} dni}}'));
        self::assertSame([], self::icuProblems('fr', $key, "{count, plural, =1 {{number} jour} other {l''intervalle de {number} jours}}"));
        self::assertNotSame([], self::icuProblems('fr', $key, '{count, plural, =1 {{number} jour}}'), 'a message with no other branch');
        self::assertNotSame([], self::icuProblems('fr', $key, '{count, plural, =1 {{number} jour} other {l\'{number} jours}}'), 'an apostrophe before a brace quotes it');
        self::assertNotSame([], self::icuProblems('fr', $key, '{count, plural, =1 {{total} jour} other {{total} jours}}'), 'an argument the key lacks');
        self::assertNotSame([], self::icuProblems('fr', $key, '{number} jours'), 'no plural selection');
        self::assertNotSame([], self::icuProblems('fr', $key, '{count, plural, =1 {} other {}}'), 'blank output');
    }

    private const PRINTF = '/%(?:\d+\$)?[-+0#]?\d*(?:\.\d+)?[sdfuxXeEgGcbo](?![A-Za-z])|%\s\d/';

    private const ENTITY = '/&(?:#\d+|#[xX][0-9A-Fa-f]+|[A-Za-z][A-Za-z0-9]*);/';

    private const SPLIT_ENTITY = '/&(?:lt|gt|amp|quot|apos|nbsp) ;|& #\d+;/';

    private const UNCLOSED_HTML_TAG = '/<[A-Za-z][^<>]*(?=<|\z)/';

    /** @var list<string> */
    private const BALANCED_HTML_TAGS = ['b', 'i', 'strong', 'em', 'u', 'a', 'code', 'span', 'p', 'div', 'li', 'ul', 'ol'];

    private const RRD_OPTION_LABEL = '/^.*\S \((--[^()&]+?)[ =]?(?:&lt;.*)?\)\z/';

    /** @var list<string> */
    private const RRD_SYNTAX_TOKENS = [
        '(dash-offset=offset)',
        '(dashes[=on_s[,off_s[,on_s,off_s]...]])',
    ];

    /** @var list<string> */
    private const CJK_LOCALES = ['ja_JP', 'ko_KR', 'zh_CN', 'zh_TW'];

    private const SPACE = '[\s\x{00A0}\x{3000}]';

    // Directional marks, embeddings, isolates and the byte order mark. Zero-width
    // joiners and non-joiners are ordinary inside Persian, Telugu and Hindi words.
    private const INVISIBLE = '[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]';

    #[DataProvider('domains')]
    public function testTranslatedValuesAreWellFormed(string $domain): void
    {
        $english = self::catalog($domain, 'en');
        $problems = [];
        $locales = self::translatedLocales();
        foreach (self::sparseYamlOverrides() as $sparse) {
            if (is_file(self::ROOT . '/config/translations/' . $domain . '.' . $sparse . '.yaml')) {
                $locales[] = $sparse;
            }
        }
        foreach ($locales as $locale) {
            foreach (self::catalog($domain, $locale) as $id => $message) {
                $id = (string) $id;
                if (!isset($english[$id])) {
                    continue;
                }
                foreach (self::valueProblems($locale, $id, $message, $english[$id]) as $reason) {
                    $problems[] = $domain . '.' . $locale . '.yaml [' . $id . ']: ' . $reason;
                }
            }
        }

        self::assertSame([], $problems);
    }

    /**
     * Reasons a translated value cannot be right, judged against the English
     * source without reading the language.
     *
     * @return list<string>
     */
    public static function valueProblems(string $locale, string $id, string $message, string $source): array
    {
        $problems = [];
        $scriptMap = self::scripts();
        $scripts = implode('', array_map(static fn(string $script): string => '\p{' . $script . '}', $scriptMap[$locale] ?? []));
        $text = (string) preg_replace(['/%\w+%/', '/<\/?[A-Za-z][^<>]*>/'], '', $message);
        if (preg_match('/(?![\p{Latin}\p{Common}\p{Inherited}' . $scripts . '])\p{L}/u', $text, $match) === 1) {
            $problems[] = 'has letters in a script foreign to ' . $locale . ', first "' . $match[0] . '"';
        }
        $printf = static fn(string $value): bool => preg_match(self::PRINTF, (string) preg_replace('/%\w+%/', '', $value)) === 1;
        if ($printf($message) && !$printf($source)) {
            $problems[] = 'has a printf-style placeholder the source lacks; Symfony substitutes only %name%';
        }
        if (preg_match(self::ENTITY, $message) === 1 && preg_match(self::ENTITY, $source) !== 1) {
            $problems[] = 'has an HTML entity the source lacks; templates escape it and show it literally';
        }
        if (preg_match(self::SPLIT_ENTITY, $message) === 1 && preg_match(self::SPLIT_ENTITY, $source) !== 1) {
            $problems[] = 'splits an HTML entity with whitespace';
        }
        if (preg_match(self::UNCLOSED_HTML_TAG, $message) === 1 && preg_match(self::UNCLOSED_HTML_TAG, $source) !== 1) {
            $problems[] = 'has an unclosed HTML tag';
        }
        foreach (self::BALANCED_HTML_TAGS as $tag) {
            $srcOpen = preg_match_all('/<' . $tag . '(?:\s[^<>]*?)?>/i', $source);
            $srcClose = preg_match_all('/<\/' . $tag . '\s*>/i', $source);
            $msgOpen = preg_match_all('/<' . $tag . '(?:\s[^<>]*?)?>/i', $message);
            $msgClose = preg_match_all('/<\/' . $tag . '\s*>/i', $message);
            if ($srcOpen === $srcClose && $msgOpen !== $msgClose) {
                $problems[] = 'has unbalanced <' . $tag . '> HTML tags';
            }
        }
        if (preg_match(self::RRD_OPTION_LABEL, $source, $optionMatch) === 1 && !str_contains($message, $optionMatch[1])) {
            $problems[] = 'drops the RRDtool option ' . $optionMatch[1];
        }
        foreach (self::RRD_SYNTAX_TOKENS as $token) {
            if (str_contains($source, $token) && !str_contains($message, $token)) {
                $problems[] = 'alters the RRDtool syntax ' . $token;
            }
        }
        if (str_contains($source, 'cacti.sql') && !str_contains($message, 'cacti.sql')) {
            $problems[] = 'alters the schema filename cacti.sql';
        }
        if (preg_match('/(?<![A-Za-z0-9_])cacti(?![A-Za-z0-9_])/i', $message) === 1 && preg_match('/(?<![A-Za-z0-9_])cacti(?![A-Za-z0-9_])/i', $source) !== 1) {
            $problems[] = 'introduces unexpected "Cacti" brand absent from source';
        }
        foreach (['/^' . self::SPACE . '*/u' => 'leading', '/' . self::SPACE . '*\z/u' => 'trailing'] as $pattern => $side) {
            preg_match($pattern, $message, $translated);
            preg_match($pattern, $source, $original);
            if ($translated !== $original) {
                $problems[] = 'differs from the source in ' . $side . ' whitespace';
            }
        }
        $colon = static fn(string $value): bool => preg_match('/[:\x{FF1A}]' . self::SPACE . '*\z/u', $value) === 1;
        if ($colon($message) !== $colon($source)) {
            $problems[] = $colon($source) ? 'drops the trailing colon of the source' : 'adds a trailing colon the source lacks';
        }
        $ending = static fn(string $value): string => (string) preg_replace('/^.*?([?!\x{FF1F}\x{FF01}][.\x{3002}])' . self::SPACE . '*\z/us', '$1', $value);
        if (preg_match('/[?!\x{FF1F}\x{FF01}][.\x{3002}]' . self::SPACE . '*\z/u', $message) === 1 && $ending($message) !== $ending($source)) {
            $problems[] = 'ends with a period after "?" or "!" that the source does not have';
        }
        $invisible = static fn(string $value): bool => preg_match('/^' . self::INVISIBLE . '|' . self::INVISIBLE . '\z/u', $value) === 1;
        if ($invisible($message) && !$invisible($source)) {
            $problems[] = 'starts or ends with an invisible directional or byte order mark';
        }
        if ($message === $id && $source !== $id) {
            $problems[] = 'repeats the symbolic key instead of translating "' . $source . '"';
        }
        if (
            $id !== $source
            && preg_match('/\A[a-z][a-z0-9_]*\z/D', $id) === 1
            && str_word_count($source) >= 4
            && mb_strlen($source) >= 24
        ) {
            $minLen = in_array($locale, self::CJK_LOCALES, true)
                ? 6
                : max(12, (int) floor(mb_strlen($source) * 0.25));
            $missingStop = str_ends_with($source, '.')
                && $locale !== 'th_TH'
                && preg_match('/[.!?。？！۔።։।]' . self::SPACE . '*\z/u', $message) !== 1;
            if (mb_strlen($message) < $minLen || $missingStop) {
                $problems[] = 'appears to translate symbolic key "' . $id . '" instead of the full sentence "' . $source . '"';
            }
        }

        return $problems;
    }

    public function testValueProblemsAreDetected(): void
    {
        $reasons = static fn(string $locale, string $id, string $message, ?string $source = null): string => implode('; ', self::valueProblems($locale, $id, $message, $source ?? $id));

        self::assertSame('', $reasons('ru_RU', 'SNMP Options', 'Параметры SNMP'));
        self::assertSame('', $reasons('ja_JP', 'Delete Device', 'デバイスを削除'));
        self::assertSame('', $reasons('fa_IR', 'Devices', "دستگاه\u{200C}ها"));
        self::assertSame('', $reasons('de_DE', '%count% of %total% [%pct%]', '%count% von %total% [%pct%]'));
        self::assertSame('', $reasons('fr', 'Usage is above 90%', 'L’utilisation dépasse 90 % depuis hier'));
        self::assertSame('', $reasons('nl_NL', 'csrf', 'Het formulier kon niet worden gecontroleerd.', 'The form could not be verified.'));
        self::assertSame('', $reasons('zh_CN', 'csrf', '无法验证表单。请重新加载并重试。', 'The form could not be verified. Reload it and try again.'));
        self::assertSame('', $reasons('th_TH', 'disabled', 'ปิดใช้งานการจัดการสินทรัพย์แล้ว', 'Asset management is disabled.'));
        self::assertSame('', $reasons('de_DE', 'Right Axis (--right-axis)', 'Rechte Achse (--right-axis)'));
        self::assertSame('', $reasons('de_DE', 'Dash Offset (dash-offset=offset)', 'Strich-Abstand (dash-offset=offset)'));
        self::assertSame('', $reasons('de_DE', 'Dashes (dashes[=on_s[,off_s[,on_s,off_s]...]])', 'Striche (dashes[=on_s[,off_s[,on_s,off_s]...]])'));
        self::assertSame('', $reasons('es_ES', 'Import cacti.sql first.', 'Importe cacti.sql primero.'));
        self::assertSame('', $reasons('de_DE', 'Kadupul timeout', 'Kadupul-Zeitlimit'));
        self::assertStringContainsString('script foreign to bg_BG', $reasons('bg_BG', 'Running', '실행 중'));
        self::assertStringContainsString('script foreign to de_DE', $reasons('de_DE', 'ALLOW', '允許'));
        self::assertStringContainsString('script foreign to ja_JP', $reasons('ja_JP', 'Unknown', '알려지지 않음'));
        self::assertStringContainsString('printf-style', $reasons('ru_RU', 'This Day', '%d День'));
        self::assertStringContainsString('printf-style', $reasons('el_GR', 'Week(s)', 'Εβδομάδα(ες) %s'));
        self::assertStringContainsString('printf-style', $reasons('pl_PL', 'Rows', '%1$s wierszy'));
        self::assertStringContainsString('printf-style', $reasons('vi_VN', 'Named Colors', '% 1 is the number of paths'));
        self::assertStringContainsString('HTML entity', $reasons('tr_TR', 'Update RRA', 'RRA&#39;yı Güncelle'));
        self::assertStringContainsString('splits an HTML entity', $reasons('fr', 'A > B', 'A &gt ; B'));
        self::assertStringContainsString('unclosed HTML tag', $reasons('de_DE', 'Bold', '<b Fett'));
        self::assertStringContainsString('unbalanced <i>', $reasons('de_DE', 'Italic', '<i>Kursiv<i>'));
        self::assertStringContainsString('drops the RRDtool option --right-axis', $reasons('de_DE', 'Right Axis (--right-axis)', 'Rechte Achse (--rechte-achse)'));
        self::assertStringContainsString('alters the RRDtool syntax (dash-offset=offset)', $reasons('de_DE', 'Dash Offset (dash-offset=offset)', 'Strich-Abstand (dash-offset=Abstand)'));
        self::assertStringContainsString('alters the RRDtool syntax (dashes[=on_s[,off_s[,on_s,off_s]...]])', $reasons('ru_RU', 'Dashes (dashes[=on_s[,off_s[,on_s,off_s]...]])', 'Тире (тире [=on_s[,off_s[,on_s,off_s]...]])'));
        self::assertStringContainsString('alters the schema filename cacti.sql', $reasons('es_ES', 'Import cacti.sql first.', 'Importe Kadupul.sql primero.'));
        self::assertStringContainsString('unexpected "Cacti" brand', $reasons('de_DE', 'Kadupul timeout', 'Cacti-Zeitlimit'));
        self::assertStringContainsString('trailing whitespace', $reasons('ko_KR', 'Graphs', '그래프 '));
        self::assertStringContainsString('leading whitespace', $reasons('tr_TR', 'Options', ' Seçenekler'));
        self::assertStringContainsString('adds a trailing colon', $reasons('ko_KR', 'Color Name', '색상명:'));
        self::assertStringContainsString('drops the trailing colon', $reasons('ja_JP', 'Licensed under the GPL:', 'GPLの下でライセンスされています。'));
        self::assertSame('', $reasons('ja_JP', 'Licensed under the GPL:', 'GPLの下でライセンスされています：'));
        self::assertStringContainsString('period after "?" or "!"', $reasons('uk_UA', 'Disable Network %name%!', 'Вимкнути мережу %name%!.'));
        self::assertStringContainsString('period after "?" or "!"', $reasons('zh_CN', 'Continue?', '继续？。'));
        self::assertSame('', $reasons('de_DE', 'Continue?', 'Fortfahren?'));
        self::assertStringContainsString('invisible', $reasons('he_IL', 'ROOT', "\u{202B}Root"));
        self::assertStringContainsString('invisible', $reasons('sv_SE', 'Numeric', "\u{FEFF}Numerisk"));
        self::assertStringContainsString('repeats the symbolic key', $reasons('nl_NL', 'csrf', 'csrf', 'The form could not be verified.'));
        self::assertStringContainsString('appears to translate symbolic key "capacity"', $reasons('af_ZA', 'capacity', 'kapasiteit', 'The pilot capacity has been reached. Review scope before expanding it.'));
        self::assertStringContainsString('appears to translate symbolic key "dependency_cycle"', $reasons('zh_TW', 'dependency_cycle', '相依性循環', 'This dependency would create a cycle.'));
    }

    public function testSourceUsesNoGettextOutsideLegacyPageHelpers(): void
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $tokens = \PhpToken::tokenize((string) file_get_contents($file->getPathname()));
            $code = array_values(array_filter($tokens, static fn(\PhpToken $token): bool => !$token->isIgnorable()));
            foreach ($code as $index => $token) {
                $previous = $code[$index - 1] ?? null;
                if (
                    // `\__()` is one T_NAME_FULLY_QUALIFIED token, not a T_STRING.
                    (($token->is(T_STRING) && in_array($token->text, self::GETTEXT_FUNCTIONS, true))
                        || ($token->is(T_NAME_FULLY_QUALIFIED) && in_array(substr($token->text, 1), self::GETTEXT_FUNCTIONS, true)))
                    && ($code[$index + 1] ?? null)?->text === '('
                    && !($previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW]))
                ) {
                    $relative = substr($file->getPathname(), strlen(self::ROOT) + 1);
                    $found[$relative] = ($found[$relative] ?? 0) + 1;
                }
            }
        }
        ksort($found);

        self::assertSame(self::GETTEXT_ALLOWLIST, $found, 'Migrated code must use Symfony Translation, not gettext.');
    }

    /** @return iterable<string, array{string}> */
    public static function templates(): iterable
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/templates', \FilesystemIterator::SKIP_DOTS));
        $count = 0;
        foreach ($files as $file) {
            if (str_ends_with($file->getFilename(), '.twig')) {
                ++$count;
                yield substr($file->getPathname(), strlen(self::ROOT) + 1) => [$file->getPathname()];
            }
        }
        self::assertGreaterThan(0, $count, 'No templates were discovered.');
    }

    #[DataProvider('templates')]
    public function testTemplatesHaveNoLiteralVisibleText(string $path): void
    {
        self::assertSame([], self::literalText((string) file_get_contents($path)));
    }

    /**
     * Visible text a template emits without a message key: text nodes and the
     * title, alt, placeholder, aria-label and button value attributes. Text inside
     * trans blocks, Twig tags and expressions is a message key. The product name
     * is a brand, not prose.
     *
     * @return list<string>
     */
    public static function literalText(string $source): array
    {
        $source = (string) preg_replace('/\{#.*?#\}/s', '', $source);
        $source = (string) preg_replace('/\{%-?\s*trans\b.*?%\}.*?\{%-?\s*endtrans\s*-?%\}/s', "\x00", $source);
        $found = [];
        $attribute = '/\b(?:title|alt|placeholder|aria-label|aria-description|label)=(?:"([^"]*)"|\'([^\']*)\')/';
        $button = '/<(?:input|button)\b[^>]*\btype=["\'](?:submit|button|reset)["\'][^>]*\bvalue=(?:"([^"]*)"|\'([^\']*)\')/';
        foreach ([$attribute, $button] as $pattern) {
            preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $found[] = self::visible(($match[1] ?? '') . ($match[2] ?? ''));
            }
        }
        $text = (string) preg_replace('/<(script|style)\b.*?<\/\1>/s', "\x00", $source);
        $text = (string) preg_replace('/\{%.*?%\}|\{\{.*?\}\}/s', "\x00", $text);
        $text = (string) preg_replace('/<[^>]*>/s', "\x00", $text);
        foreach (explode("\x00", $text) as $segment) {
            $found[] = self::visible($segment);
        }
        $scripts = [];
        preg_match_all('/<script\b[^>]*>(.*?)<\/script>/s', $source, $scripts);
        foreach ($scripts[1] as $script) {
            if (str_contains($script, 'preferredRealm = storage.get(') && str_contains($script, 'preferredRealm = null;') && str_contains($script, "preferredRealm = $('#realm option:selected').val();")) {
                continue;
            }
            preg_match_all('/(["\'])((?:(?!\1)[^\\\\]|\\\\.)*?[A-Za-z]+ [A-Za-z]+(?:(?!\1)[^\\\\]|\\\\.)*?)\1/', $script, $strings);
            foreach ($strings[2] as $string) {
                if (trim($script) !== '' && preg_match('/\A\s*alert\s*\(\s*(["\'])' . preg_quote($string, '/') . '\1\s*\)\s*;?\s*\z/', $script) === 1) {
                    $found[] = self::visible($string);
                    continue;
                }
                if (preg_match('/^[#.]?[A-Za-z_$][\w$]*(?:\s+[A-Za-z_$][\w$]*)*(?::[A-Za-z_$][\w$]*)?$/', $string) === 1) {
                    continue;
                }
                $found[] = self::visible($string);
            }
        }

        return array_values(array_filter($found, static fn(string $text): bool => $text !== ''));
    }

    private static function visible(string $text): string
    {
        $text = (string) preg_replace('/\{\{.*?\}\}|\{%.*?%\}/s', '', $text);
        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode($text)));

        // Attribute fragments from block overrides and name=value previews are markup, not prose.
        if (preg_match('/^[\w-]+=/', $text) === 1) {
            return '';
        }

        return preg_match('/[A-Za-z]{2}/', str_replace('Kadupul', '', $text)) === 1 ? $text : '';
    }

    public function testLiteralTextDetectionFindsUntranslatedMarkup(): void
    {
        self::assertSame([], self::literalText("<h1>{{ 'Sites'|trans({}, 'inventory') }} · Kadupul</h1>{% trans %}Hello <b>x</b>{% endtrans %}"));
        self::assertSame(['Sites'], self::literalText('<h1>Sites</h1>'));
        self::assertSame(['Search'], self::literalText('<input title="Search" value="{{ x }}">'));
        self::assertSame(['Go'], self::literalText('<input type="submit" value="Go">'));
        self::assertSame(['Close dialog'], self::literalText("<script>alert('Close dialog');</script>"));
        self::assertSame([], self::literalText('<option value="ASC">{{ x }}</option><script>var a = "x";</script>'));
    }
}
