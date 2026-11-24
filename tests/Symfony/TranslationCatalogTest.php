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

    /** @var list<string> */
    private const TRANSLATED_LOCALES = [
        'af_ZA', 'am_ET', 'ar_SA', 'bg_BG', 'bn_BD', 'de_DE', 'el_GR', 'es_ES',
        'fa_IR', 'fr', 'fr_SN', 'ha_NG', 'he_IL', 'hi_IN', 'id_ID', 'ig_NG',
        'it_IT', 'ja_JP', 'ko_KR', 'lv_LV', 'mr_IN', 'nl_NL', 'pl_PL', 'pt_BR',
        'pt_PT', 'ru_RU', 'sv_SE', 'sw_KE', 'ta_IN', 'te_IN', 'th_TH', 'tr_TR',
        'uk_UA', 'ur_PK', 'vi_VN', 'xh_ZA', 'yo_NG', 'zh_CN', 'zh_TW', 'zu_ZA',
    ];

    #[DataProvider('domains')]
    public function testEnglishAndTranslatedLocalesDefineTheSameMessages(string $domain): void
    {
        $english = self::catalog($domain, 'en');
        foreach (self::TRANSLATED_LOCALES as $locale) {
            $translated = self::catalog($domain, $locale);
            self::assertSame([], array_values(array_diff_key($english, $translated)), $domain . ' is missing ' . $locale . ' messages');
            self::assertSame([], array_values(array_diff_key($translated, $english)), $domain . ' has extra ' . $locale . ' messages');
        }
    }

    #[DataProvider('domains')]
    public function testMessagesAreNotBlankAndKeepPlaceholders(string $domain): void
    {
        $english = self::catalog($domain, 'en');
        foreach (self::TRANSLATED_LOCALES as $locale) {
            foreach (self::catalog($domain, $locale) as $id => $message) {
                $id = (string) $id;
                self::assertNotSame('', trim($message), $domain . ' ' . $locale . ': ' . $id);
                // A plural form may drop %count% ("Every %count% Day" becomes "Chaque jour") but must not invent one.
                self::assertSame([], array_diff(self::placeholders($message), self::placeholders($id)), $domain . ' ' . $locale . ': ' . $id);
            }
        }
        foreach ($english as $id => $message) {
            self::assertNotSame('', trim($message), $domain . ' en: ' . $id);
        }
    }

    /** @return list<string> */
    private static function placeholders(string $message): array
    {
        preg_match_all('/%\w+%/', $message, $matches);

        return $matches[0];
    }

    /**
     * Unicode scripts each locale is written in. Latin is accepted everywhere:
     * product names, acronyms, units and code-like tokens stay as they are.
     */
    private const SCRIPTS = [
        'am_ET' => ['Ethiopic'], 'ar_SA' => ['Arabic'], 'bg_BG' => ['Cyrillic'], 'bn_BD' => ['Bengali'],
        'el_GR' => ['Greek'], 'fa_IR' => ['Arabic'], 'he_IL' => ['Hebrew'], 'hi_IN' => ['Devanagari'],
        'ja_JP' => ['Han', 'Hiragana', 'Katakana'], 'ko_KR' => ['Hangul', 'Han'], 'mr_IN' => ['Devanagari'],
        'ru_RU' => ['Cyrillic'], 'ta_IN' => ['Tamil'], 'te_IN' => ['Telugu'], 'th_TH' => ['Thai'],
        'uk_UA' => ['Cyrillic'], 'ur_PK' => ['Arabic'], 'zh_CN' => ['Han'], 'zh_TW' => ['Han'],
    ];

    private const PRINTF = '/%(?:\d+\$)?[-+0#]?\d*(?:\.\d+)?[sdfuxXeEgGcbo](?![A-Za-z])|%\s\d/';

    private const ENTITY = '/&(?:#\d+|#[xX][0-9A-Fa-f]+|[A-Za-z][A-Za-z0-9]*);/';

    private const SPACE = '[\s\x{00A0}\x{3000}]';

    // Directional marks, embeddings, isolates and the byte order mark. Zero-width
    // joiners and non-joiners are ordinary inside Persian, Telugu and Hindi words.
    private const INVISIBLE = '[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]';

    #[DataProvider('domains')]
    public function testTranslatedValuesAreWellFormed(string $domain): void
    {
        $english = self::catalog($domain, 'en');
        $problems = [];
        foreach (self::TRANSLATED_LOCALES as $locale) {
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
        $scripts = implode('', array_map(static fn(string $script): string => '\p{' . $script . '}', self::SCRIPTS[$locale] ?? []));
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
        $invisible = static fn(string $value): bool => preg_match('/^' . self::INVISIBLE . '|' . self::INVISIBLE . '\z/u', $value) === 1;
        if ($invisible($message) && !$invisible($source)) {
            $problems[] = 'starts or ends with an invisible directional or byte order mark';
        }
        if ($message === $id && $source !== $id) {
            $problems[] = 'repeats the symbolic key instead of translating "' . $source . '"';
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
        self::assertStringContainsString('script foreign to bg_BG', $reasons('bg_BG', 'Running', '실행 중'));
        self::assertStringContainsString('script foreign to de_DE', $reasons('de_DE', 'ALLOW', '允許'));
        self::assertStringContainsString('script foreign to ja_JP', $reasons('ja_JP', 'Unknown', '알려지지 않음'));
        self::assertStringContainsString('printf-style', $reasons('ru_RU', 'This Day', '%d День'));
        self::assertStringContainsString('printf-style', $reasons('el_GR', 'Week(s)', 'Εβδομάδα(ες) %s'));
        self::assertStringContainsString('printf-style', $reasons('pl_PL', 'Rows', '%1$s wierszy'));
        self::assertStringContainsString('printf-style', $reasons('vi_VN', 'Named Colors', '% 1 is the number of paths'));
        self::assertStringContainsString('HTML entity', $reasons('tr_TR', 'Update RRA', 'RRA&#39;yı Güncelle'));
        self::assertStringContainsString('trailing whitespace', $reasons('ko_KR', 'Graphs', '그래프 '));
        self::assertStringContainsString('leading whitespace', $reasons('tr_TR', 'Options', ' Seçenekler'));
        self::assertStringContainsString('adds a trailing colon', $reasons('ko_KR', 'Color Name', '색상명:'));
        self::assertStringContainsString('drops the trailing colon', $reasons('ja_JP', 'Licensed under the GPL:', 'GPLの下でライセンスされています。'));
        self::assertSame('', $reasons('ja_JP', 'Licensed under the GPL:', 'GPLの下でライセンスされています：'));
        self::assertStringContainsString('invisible', $reasons('he_IL', 'ROOT', "\u{202B}Root"));
        self::assertStringContainsString('invisible', $reasons('sv_SE', 'Numeric', "\u{FEFF}Numerisk"));
        self::assertStringContainsString('repeats the symbolic key', $reasons('nl_NL', 'csrf', 'csrf', 'The form could not be verified.'));
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
