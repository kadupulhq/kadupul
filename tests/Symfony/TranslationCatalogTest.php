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
        'src/IdentityAccess/Infrastructure/Legacy/PermissionFilter.php' => 7,
    ];

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

    /** @return array<string, string> */
    private static function catalog(string $domain, string $locale): array
    {
        $file = self::ROOT . '/config/translations/' . $domain . '.' . $locale . '.yaml';
        self::assertFileExists($file);
        $messages = Yaml::parseFile($file);
        self::assertIsArray($messages);
        foreach ($messages as $id => $message) {
            self::assertIsString($message, $domain . '.' . $locale . ': ' . $id);
        }

        return array_combine(array_map('strval', array_keys($messages)), $messages);
    }

    #[DataProvider('domains')]
    public function testEnglishAndFrenchDefineTheSameMessages(string $domain): void
    {
        $english = self::catalog($domain, 'en');
        $french = self::catalog($domain, 'fr');

        self::assertSame([], array_values(array_diff_key($english, $french)), $domain . ' is missing French messages');
        self::assertSame([], array_values(array_diff_key($french, $english)), $domain . ' is missing English messages');
    }

    #[DataProvider('domains')]
    public function testMessagesAreNotBlankAndKeepPlaceholders(string $domain): void
    {
        $english = self::catalog($domain, 'en');
        foreach (self::catalog($domain, 'fr') as $id => $message) {
            $id = (string) $id;
            self::assertNotSame('', trim($message), $domain . ' fr: ' . $id);
            // A plural form may drop %count% ("Every %count% Day" becomes "Chaque jour") but must not invent one.
            self::assertSame([], array_diff(self::placeholders($message), self::placeholders($id)), $domain . ' fr: ' . $id);
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
                    $token->is(T_STRING) && in_array($token->text, ['__', '__esc', '_n', '__x', '_x', 'gettext', '_'], true)
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
            preg_match_all('/(["\'])((?:(?!\1)[^\\\\]|\\\\.)*?[A-Za-z]+ [A-Za-z]+(?:(?!\1)[^\\\\]|\\\\.)*?)\1/', $script, $strings);
            foreach ($strings[2] as $string) {
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
