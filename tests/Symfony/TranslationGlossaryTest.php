<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Validates the canonical multilingual terminology glossary (locales/glossary.json)
 * against the shipped locale manifest (locales/catalogs.json).
 */
final class TranslationGlossaryTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** @var list<string> */
    private const REQUIRED_IMMUTABLE_TOKENS = [
        'Kadupul',
        'OID',
        'RRDtool',
        'SNMP',
        'SQL',
    ];

    /** @var list<string> */
    private const REQUIRED_TERMS = [
        'CDEF',
        'Data Source',
        'Flush',
        'Graph Template',
        'Heartbeat',
        'Poller',
        'RRA',
        'RRD',
        'Step',
        'Threshold',
        'VDEF',
    ];

    private const FORBIDDEN_CHARS = '/[\p{Cc}\x{200B}\x{200E}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}\x{0640}\x{00AD}]/u';

    private const HTML_ENTITY = '/&(?:#\d+|#[xX][0-9A-Fa-f]+|[A-Za-z][A-Za-z0-9]*);/';

    public function testGlossarySchemaLocaleCoverageScriptsAndImmutableTokens(): void
    {
        $catalogs = json_decode((string) file_get_contents(self::ROOT . '/locales/catalogs.json'), true, 16, JSON_THROW_ON_ERROR);
        $glossary = json_decode((string) file_get_contents(self::ROOT . '/locales/glossary.json'), true, 16, JSON_THROW_ON_ERROR);

        self::assertIsArray($catalogs);
        self::assertIsArray($glossary);
        self::assertSame(1, $glossary['version'] ?? null);
        self::assertSame($catalogs['source_fallback'] ?? null, $glossary['source_locale'] ?? null);

        $declaredLocales = $catalogs['translated_locales'] ?? null;
        $scripts = $catalogs['scripts'] ?? null;
        self::assertIsArray($declaredLocales);
        self::assertIsArray($scripts);

        $expectedLocales = array_merge([$catalogs['source_fallback']], $declaredLocales);
        sort($expectedLocales);

        // Validate immutable technical and brand tokens.
        $immutable = $glossary['immutable_tokens'] ?? null;
        self::assertIsArray($immutable);
        foreach (self::REQUIRED_IMMUTABLE_TOKENS as $token) {
            self::assertArrayHasKey($token, $immutable, 'Missing required immutable token: ' . $token);
            self::assertIsString($immutable[$token]);
            self::assertNotSame('', trim($immutable[$token]));
        }

        // Validate core domain terms.
        $terms = $glossary['terms'] ?? null;
        self::assertIsArray($terms);
        foreach (self::REQUIRED_TERMS as $term) {
            self::assertArrayHasKey($term, $terms, 'Missing required glossary term: ' . $term);
        }

        $problems = [];
        foreach ($terms as $term => $entry) {
            self::assertIsString($term);
            self::assertIsArray($entry, 'Glossary entry must be an object: ' . $term);
            $description = $entry['description'] ?? null;
            self::assertIsString($description, $term . ' must have a description');
            self::assertNotSame('', trim($description), $term . ' description cannot be blank');

            $translations = $entry['translations'] ?? null;
            self::assertIsArray($translations, $term . ' must define translations');

            $actualLocales = array_keys($translations);
            sort($actualLocales);
            self::assertSame($expectedLocales, $actualLocales, $term . ' locale coverage differs from locales/catalogs.json');
            self::assertSame($term, $translations['en-US'] ?? null, $term . ' en-US entry must match the canonical term');

            foreach ($translations as $locale => $value) {
                self::assertIsString($locale);
                self::assertIsString($value, $term . ' [' . $locale . '] must be a string');
                if (trim($value) === '' || $value !== trim($value)) {
                    $problems[] = $term . ' [' . $locale . ']: blank or untrimmed value';
                    continue;
                }
                if (preg_match(self::FORBIDDEN_CHARS, $value) === 1) {
                    $problems[] = $term . ' [' . $locale . ']: contains control or forbidden invisible character';
                }
                if (preg_match(self::HTML_ENTITY, $value) === 1) {
                    $problems[] = $term . ' [' . $locale . ']: contains an HTML entity';
                }
                if (preg_match('/\bcacti\b/i', $value) === 1) {
                    $problems[] = $term . ' [' . $locale . ']: references Cacti instead of Kadupul';
                }
                $allowedScripts = $scripts[$locale] ?? [];
                self::assertIsArray($allowedScripts);
                $scriptPatterns = [];
                foreach ($allowedScripts as $script) {
                    self::assertIsString($script);
                    $scriptPatterns[] = '\p{' . $script . '}';
                }
                $scriptPattern = implode('', $scriptPatterns);
                if (preg_match('/(?![\p{Latin}\p{Common}\p{Inherited}' . $scriptPattern . '])\p{L}/u', $value, $match) === 1) {
                    $problems[] = $term . ' [' . $locale . ']: has foreign script letter "' . $match[0] . '"';
                }
            }
        }

        self::assertSame([], $problems);
    }
}
