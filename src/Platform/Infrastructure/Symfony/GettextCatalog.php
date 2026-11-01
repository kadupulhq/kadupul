<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

use Symfony\Component\Translation\Loader\PoFileLoader;

/**
 * One shipped gettext .po catalog, read for Symfony message keys.
 *
 * Symfony keys are the English source text with %name% placeholders, and the
 * .po msgids are the same text with printf conversions. A key resolves only
 * when the conversion is unambiguous; anything else stays untranslated so the
 * translator falls back to English.
 */
final class GettextCatalog
{
    private const string TOKEN = '/(%[A-Za-z_]\w*%)/';

    /**
     * @param array<string, string> $messages PoFileLoader messages; plural ids are "singular|plural"
     * @param array<string, true> $contextual msgids that also occur with a msgctxt
     * @param array<string, string> $plurals singular msgid => plural msgid
     */
    private function __construct(private readonly array $messages, private readonly array $contextual, private readonly array $plurals, private readonly ?int $pluralShape) {}

    public static function fromFile(string $path): self
    {
        $messages = [];
        foreach ((new PoFileLoader())->load($path, 'en')->all('messages') as $id => $message) {
            if (is_string($message)) {
                $messages[(string) $id] = $message;
            }
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException('Gettext catalog is unreadable.');
        }
        $contextual = [];
        $plurals = [];
        $header = null;
        $entry = [];
        $field = null;
        // PoFileLoader drops msgctxt and the header, so read just those fields here.
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/\A(msgctxt|msgid_plural|msgid|msgstr(?:\[\d+\])?) "(.*)"\z/', $line, $match) === 1) {
                if (($match[1] === 'msgctxt' || $match[1] === 'msgid') && isset($entry['msgid'])) {
                    self::record($entry, $contextual, $plurals, $header);
                    $entry = [];
                }
                $field = $match[1];
                $entry[$field] = $match[2];
            } elseif ($field !== null && preg_match('/\A"(.*)"\z/', $line, $match) === 1) {
                $entry[$field] = ($entry[$field] ?? '') . $match[1];
            } elseif ($line === '') {
                self::record($entry, $contextual, $plurals, $header);
                $entry = [];
                $field = null;
            }
        }
        self::record($entry, $contextual, $plurals, $header);

        return new self($messages, $contextual, $plurals, self::pluralShape($header));
    }

    /**
     * @param array<string, string> $entry
     * @param array<string, true> $contextual
     * @param array<string, string> $plurals
     */
    private static function record(array $entry, array &$contextual, array &$plurals, ?string &$header): void
    {
        if (!isset($entry['msgid'])) {
            return;
        }
        $id = stripcslashes($entry['msgid']);
        if ($id === '') {
            $header ??= stripcslashes($entry['msgstr'] ?? '');
        } elseif (isset($entry['msgctxt'])) {
            $contextual[$id] = true;
        } elseif (isset($entry['msgid_plural'])) {
            $plurals[$id] = stripcslashes($entry['msgid_plural']);
        }
    }

    /** Translation for a Symfony message key, or null when the catalog has no unambiguous one. */
    public function translate(string $key): ?string
    {
        $parts = preg_split(self::TOKEN, $key, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return null;
        }
        $tokens = [];
        foreach ($parts as $index => $part) {
            if ($index % 2 === 1) {
                $tokens[] = $part;
            } elseif (str_contains($part, '%')) {
                return null;
            }
        }
        foreach ($tokens === [] ? [''] : ['%s', '%d'] as $conversion) {
            $msgid = implode('', array_map(static fn(string $part, int $index): string => $index % 2 === 1 ? $conversion : $part, $parts, array_keys($parts)));
            $message = $this->message($msgid);
            if ($message !== null) {
                return self::placeholders($message, $tokens);
            }
        }

        return null;
    }

    private function message(string $msgid): ?string
    {
        if ($msgid === '' || isset($this->contextual[$msgid])) {
            return null;
        }
        $singular = isset($this->plurals[$msgid]) ? $msgid : array_search($msgid, $this->plurals, true);
        if ($singular === false) {
            $message = $this->messages[$msgid] ?? '';

            return $message === '' || str_contains($message, '|') ? null : $message;
        }
        // Migrated pages pick the singular or plural key with the English rule
        // (n == 1). Only catalogs whose own rule is that rule, or that have one
        // form, give the same answer; other locales keep the English text.
        if ($this->pluralShape === null) {
            return null;
        }
        $forms = explode('|', $this->messages[$singular . '|' . $this->plurals[$singular]] ?? '');
        if (count($forms) !== $this->pluralShape || in_array('', $forms, true)) {
            return null;
        }

        return $forms[$msgid === $singular || $this->pluralShape === 1 ? 0 : 1];
    }

    /** @param list<string> $tokens */
    private static function placeholders(string $message, array $tokens): ?string
    {
        $parts = preg_split('/(%%|%(?:[1-9]\d*\$)?[sd])/', $message, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return null;
        }
        $result = '';
        $next = 0;
        $positional = null;
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                if (str_contains($part, '%')) {
                    return null;
                }
                $result .= $part;
                continue;
            }
            if ($part === '%%') {
                $result .= '%';
                continue;
            }
            $explicit = str_contains($part, '$');
            if ($positional !== null && $positional !== $explicit) {
                return null;
            }
            $positional = $explicit;
            $position = $explicit ? (int) substr($part, 1) - 1 : $next++;
            if (!isset($tokens[$position])) {
                return null;
            }
            $result .= $tokens[$position];
        }

        return $result;
    }

    /** 2 when the rule is English's n != 1, 1 for a single form, otherwise null. */
    private static function pluralShape(?string $header): ?int
    {
        if ($header === null || preg_match('/^Plural-Forms:\s*nplurals\s*=\s*(\d+)\s*;\s*plural\s*=([^;\n]*);?\s*$/mi', $header, $match) !== 1) {
            return null;
        }
        $expression = preg_replace('/\s+/', '', $match[2]) ?? '';
        while (preg_match('/\A\(([^()]*)\)\z/', $expression, $inner) === 1) {
            $expression = $inner[1];
        }

        return match ([(int) $match[1], $expression]) {
            [2, 'n!=1'] => 2,
            [1, '0'] => 1,
            default => null,
        };
    }
}
