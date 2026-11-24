<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Translation;

/**
 * What the legacy translation runtime renders today, for every shipped locale
 * and en-US. Each call goes through the real include/global_languages.php
 * helpers and php-gettext over the shipped .mo files, in a child process per
 * locale (the runtime defines constants and globals once per process).
 *
 * The calls are every catalog identity (cacti.pot plus the reviewed historical
 * identities) through __gettext, __x, __n and __xn, and fixed cases for
 * sprintf, %% and the "last argument is a domain only if loaded" rule. Two
 * fixture plugins are installed: i18nprobe and folder, both German only.
 *
 * @phpstan-type Call array{0: string, 1: list<int|string>}
 * @phpstan-type Result array{value: mixed, error: ?string, warnings: list<string>}
 * @phpstan-type Rendering array{locale: string, handler: int, domains: array<string, string>, readers: array<string, string>, plural_index: array<string, list<int|bool>>, values: list<mixed>, errors: array<int, string>, warnings: array<int, list<string>>, log: list<string>}
 */
final class RenderedCorpus
{
    /** Counts every plural identity is rendered with. */
    public const array COUNTS = [0, 1, 2, 3, 4, 5, 11, 12, 21, 22, 100, 101, 111];

    public const array PLUGINS = ['i18nprobe', 'folder'];

    /** i18n_language_handler as the settings table stores it. */
    public const string PHP_GETTEXT = '1';

    public const string NO_HANDLER = '';

    /** CACTI_LANGUAGE_HANDLER_* in include/global_constants.php. */
    public const array HANDLERS = ['' => 4, '0' => 4, '1' => 1, '4' => 4];

    private const string ORACLE = __DIR__ . '/../Fixtures/translation/legacy-gettext-oracle.php';

    private const string PLUGIN_FIXTURES = __DIR__ . '/../Fixtures/translation/plugins';

    private static ?self $shared = null;

    /**
     * @param list<Call> $calls
     * @param array<string, Rendering> $renderings
     * @param list<string> $english locales the caller expects to render in English
     */
    private function __construct(public readonly array $calls, public readonly array $renderings, private readonly array $english = []) {}

    /** The corpus of this checkout, rendered once per test process. */
    public static function shared(): self
    {
        return self::$shared ??= self::render(self::root(), self::locales(self::root()), self::calls(self::root()));
    }

    /** Frees the shared corpus; a test class that used it calls this when done. */
    public static function release(): void
    {
        self::$shared = null;
    }

    public static function root(): string
    {
        $root = realpath(__DIR__ . '/../../..');
        if ($root === false) {
            throw new \RuntimeException('Project root is unavailable.');
        }

        return $root;
    }

    /** @return list<string> en-US followed by every shipped catalog */
    public static function locales(string $root): array
    {
        $manifest = json_decode((string) file_get_contents($root . '/locales/catalogs.json'), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || !is_array($manifest['translated_locales'] ?? null) || $manifest['translated_locales'] === []) {
            throw new \RuntimeException('The shipped locale manifest is unusable.');
        }

        return ['en-US', ...array_map('strval', array_values($manifest['translated_locales']))];
    }

    /**
     * Catalog identities as [context, msgid, msgid_plural], '' for absent parts.
     *
     * @return list<array{string, string, string}>
     */
    public static function identities(string $root): array
    {
        $identities = [];
        foreach (self::potIdentities((string) file_get_contents($root . '/locales/po/cacti.pot')) as $identity) {
            $identities[implode("\0", $identity)] = $identity;
        }
        $historical = json_decode((string) file_get_contents($root . '/locales/historical-compatibility.json'), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($historical) || !is_array($historical['identities'] ?? null) || $historical['identities'] === []) {
            throw new \RuntimeException('The historical compatibility inventory is unusable.');
        }
        foreach ($historical['identities'] as $item) {
            $identity = is_array($item) ? ($item['identity'] ?? null) : null;
            if (!is_array($identity) || count($identity) !== 3 || !is_string($identity[0]) || !is_string($identity[1]) || !is_string($identity[2])) {
                throw new \RuntimeException('Invalid historical identity.');
            }
            $identities[implode("\0", $identity)] = [$identity[0], $identity[1], $identity[2]];
        }

        return array_values($identities);
    }

    /**
     * Identities of a POT or PO file, without the header entry.
     *
     * @return list<array{string, string, string}>
     */
    public static function potIdentities(string $text): array
    {
        $identities = [];
        foreach (preg_split('/\n[ \t]*\n/', $text) ?: [] as $block) {
            $fields = [];
            $field = null;
            foreach (explode("\n", $block) as $line) {
                if (preg_match('/^(msgctxt|msgid_plural|msgid|msgstr(?:\[\d+\])?) "(.*)"$/', $line, $match) === 1) {
                    $field = $match[1];
                    $fields[$field] = stripcslashes($match[2]);
                } elseif ($field !== null && preg_match('/^"(.*)"$/', $line, $match) === 1) {
                    $fields[$field] .= stripcslashes($match[1]);
                } elseif (!str_starts_with($line, '#') && trim($line) !== '') {
                    throw new \RuntimeException('Unrecognised catalog line: ' . $line);
                }
            }
            if (($fields['msgid'] ?? '') !== '') {
                $identities[] = [$fields['msgctxt'] ?? '', $fields['msgid'], $fields['msgid_plural'] ?? ''];
            }
        }

        return $identities;
    }

    /** @return list<Call> */
    public static function calls(string $root): array
    {
        $calls = [];
        foreach (self::identities($root) as [$context, $singular, $plural]) {
            if ($plural === '') {
                $calls[] = $context === '' ? ['__gettext', [$singular]] : ['__x', [$context, $singular]];
                continue;
            }
            foreach (self::COUNTS as $n) {
                $calls[] = $context === '' ? ['__n', [$singular, $plural, $n]] : ['__xn', [$context, $singular, $plural, $n]];
            }
        }

        return [...$calls, ...self::fixedCalls()];
    }

    /**
     * sprintf, %% and text-domain cases that identities alone do not reach.
     *
     * @return list<Call>
     */
    public static function fixedCalls(): array
    {
        $calls = [
            // An empty msgid returns the catalog header.
            ['__', ['']],
            ['__', ['%d Minutes', 5]],
            ['__', ['Version %s | %s', '1.2', 'Kadupul']],
            // %%% followed by ':' is not a valid conversion once sprintf runs.
            ['__', ['Variance %%%:    %s %%%', '12']],
            ['__', ['Choose a Graph Template to apply to this Graph. Please note that you may only change Graph Templates to a 100%% compatible Graph Template, which means that it includes identical Data Sources.']],
            ['__', ['Choose a Graph Template to apply to this Graph. Please note that you may only change Graph Templates to a 100%% compatible Graph Template, which means that it includes identical Data Sources.', 'pagkage']],
            // Second arguments that only look like domains (include/global_form.php,
            // templates_import.php, lib/rrd.php).
            ['__', ['Graph Template Height', 'pagkage']],
            ['__', ['Differences', 'package']],
            ['__', ['Website does not have write access to %s, may be unable to create/update RRDs', 'folder']],
            ['__', ['Folder', 'folder']],
            ['__', ['Devices', 'cacti']],
            ['__', ['Devices', 'folder']],
            ['__', ['Devices', 'i18nprobe']],
            ['__', ['Delete', 'i18nprobe']],
            ['__', ['%d Minutes', 5, 'i18nprobe']],
            ['__', ['Probe %s ready', 'A1', 'i18nprobe']],
            ['__', ['Usage %d%%', 50, 'i18nprobe']],
            // The domain name becomes the only sprintf argument.
            ['__', ['Usage %d%%', 'i18nprobe']],
            ['__', ['Plain value', 'i18nprobe']],
            ['__', ['Left|Right', 'i18nprobe']],
            ['__', ['Fuzzy probe', 'i18nprobe']],
            ['__', ['Untranslated probe', 'i18nprobe']],
            ['__x', ['dropdown action', 'Delete']],
            ['__x', ['dropdown action', 'Delete', 'i18nprobe']],
            ['__x', ['probe', 'Delete', 'i18nprobe']],
            ['__x', ['probe', 'Delete']],
            // __date() reads a falsy timestamp as now, so 0 would not be stable.
            ['__date', ['D, d M Y', 345600]],
            ['__date', ['l, j F Y H:i', 1700000000]],
            // php-gettext rejects a count that is not an int.
            ['__n', ['Delete Data Query', 'Delete Data Queries', '2']],
            // A text domain no catalog was loaded for.
            ['__gettext', ['Devices', 'absent']],
            ['__n', ['Delete Data Query', 'Delete Data Queries', 2, 'absent']],
            ['__n', ['Delete Data Query', 'Delete Data Queries', 1, 'absent']],
            ['__xn', ['probe', 'Item', 'Items', 2, 'absent']],
            ['__x', ['dropdown action', 'Delete', 'absent']],
            ['__gettext', ['Devices', 'i18nprobe']],
            ['__gettext', ['Untranslated probe', 'i18nprobe']],
        ];
        foreach (self::COUNTS as $n) {
            $calls[] = ['__n', ['%d probe', '%d probes', $n, 'i18nprobe']];
            $calls[] = ['__n', ['One|item', 'Many|items', $n, 'i18nprobe']];
            $calls[] = ['__n', ['%d slot', '%d slots', $n, 'i18nprobe']];
            $calls[] = ['__n', ['Delete Data Query', 'Delete Data Queries', $n, 'i18nprobe']];
            $calls[] = ['__xn', ['probe', 'Item', 'Items', $n, 'i18nprobe']];
        }

        return $calls;
    }

    /**
     * Renders the calls for each locale and checks the result is a usable corpus.
     *
     * @param list<string> $locales
     * @param list<Call> $calls
     * @param ?string $symfonyCache render over Symfony catalogues compiled here instead of php-gettext
     * @param string $handler the i18n_language_handler setting
     * @param array<string, array{string, int}> $expect the locale and handler the runtime must end with, by
     *     requested locale; the requested locale and $handler's own handler otherwise, English without one
     * @param ?string $applicationCache the installation's var/cache, kept between renders; a throwaway one otherwise
     * @param array<string, string> $catalogs files that replace a core catalog, by file name (de-DE.mo)
     */
    public static function render(string $root, array $locales, array $calls, ?string $symfonyCache = null, string $handler = self::PHP_GETTEXT, array $expect = [], ?string $applicationCache = null, array $catalogs = []): self
    {
        if ($calls === [] || $locales === []) {
            throw new \RuntimeException('An empty corpus proves nothing.');
        }
        // The Symfony handler keys its compiled catalogues by catalog path, so
        // renders that share an application cache get the same installation path.
        $work = sys_get_temp_dir() . '/kadupul-corpus-' . ($applicationCache === null ? bin2hex(random_bytes(8)) : substr(hash('sha256', $applicationCache), 0, 16));
        if (!mkdir($work, 0700)) {
            throw new \RuntimeException('Cannot create the corpus work directory.');
        }
        $owned = [];
        try {
            $base = $work . '/install';
            $owned[] = $base;
            mkdir($base . '/plugins', 0700, true);
            $owned[] = $base . '/plugins';
            if ($catalogs === []) {
                self::link($root . '/locales', $base . '/locales');
                $owned[] = $base . '/locales';
            } else {
                mkdir($base . '/locales/LC_MESSAGES', 0700, true);
                array_push($owned, $base . '/locales', $base . '/locales/LC_MESSAGES');
                foreach (glob($root . '/locales/LC_MESSAGES/*.mo') ?: [] as $catalog) {
                    self::link($catalogs[basename($catalog)] ?? $catalog, $base . '/locales/LC_MESSAGES/' . basename($catalog));
                    $owned[] = $base . '/locales/LC_MESSAGES/' . basename($catalog);
                }
            }
            if ($applicationCache !== null) {
                mkdir($base . '/var', 0700);
                $owned[] = $base . '/var';
                self::link($applicationCache, $base . '/var/cache');
                $owned[] = $base . '/var/cache';
            }
            foreach (self::PLUGINS as $plugin) {
                self::link(self::PLUGIN_FIXTURES . '/' . $plugin, $base . '/plugins/' . $plugin);
                $owned[] = $base . '/plugins/' . $plugin;
            }
            $callsFile = $work . '/calls';
            $owned[] = $callsFile;
            file_put_contents($callsFile, serialize($calls));
            $renderings = [];
            foreach (array_chunk($locales, 8) as $batch) {
                $processes = [];
                foreach ($batch as $locale) {
                    $output = $work . '/' . $locale . '.out';
                    $errors = $work . '/' . $locale . '.err';
                    $owned[] = $output;
                    $owned[] = $errors;
                    [$expectedLocale, $expectedHandler] = $expect[$locale] ?? ($locale === 'en-US' ? ['en-US', 0] : [$locale, self::HANDLERS[$handler]]);
                    $command = [PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', self::ORACLE, $root, $base, $locale, $callsFile, $output, $handler, $expectedLocale, (string) $expectedHandler];
                    if ($symfonyCache !== null) {
                        $command[] = $symfonyCache;
                    }
                    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $errors, 'w'], 2 => ['file', $errors, 'a']], $pipes);
                    if (!is_resource($process)) {
                        throw new \RuntimeException('Cannot start the legacy oracle for ' . $locale);
                    }
                    $processes[$locale] = [$process, $output, $errors];
                }
                foreach ($processes as $locale => [$process, $output, $errors]) {
                    $status = proc_close($process);
                    $diagnostics = trim((string) file_get_contents($errors));
                    if ($status !== 0 || $diagnostics !== '' || !is_file($output)) {
                        throw new \RuntimeException(sprintf('Legacy oracle for %s exited %d: %s', $locale, $status, $diagnostics));
                    }
                    $rendering = unserialize((string) file_get_contents($output), ['allowed_classes' => false]);
                    if (!is_array($rendering)) {
                        throw new \RuntimeException('Unreadable rendering for ' . $locale);
                    }
                    /** @var Rendering $rendering */
                    $renderings[$locale] = $rendering;
                }
            }
        } finally {
            if ($applicationCache === null) {
                // Catalogues the Symfony handler compiled below the throwaway installation.
                self::remove($base . '/var');
            }
            foreach (array_reverse($owned) as $path) {
                if (is_link($path) || is_file($path)) {
                    unlink($path);
                } elseif (is_dir($path)) {
                    rmdir($path);
                }
            }
            rmdir($work);
        }
        $corpus = new self($calls, $renderings, [...array_keys($expect), ...array_map(static fn(string $file): string => basename($file, '.mo'), array_keys($catalogs))]);
        $corpus->assertUsable();

        return $corpus;
    }

    /**
     * A corpus is usable only if every locale rendered every call, and every
     * translated locale changed some of them. An empty corpus, a missing locale
     * or a catalog that silently fell back to English would otherwise make any
     * later comparison pass. Only a locale the caller named in $expect or
     * replaced the catalog of may render in English.
     */
    public function assertUsable(): void
    {
        if ($this->calls === [] || $this->renderings === [] || !isset($this->renderings['en-US'])) {
            throw new \RuntimeException('The corpus is empty.');
        }
        foreach ($this->renderings as $locale => $rendering) {
            if (count($rendering['values']) !== count($this->calls)) {
                throw new \RuntimeException(sprintf('%s rendered %d of %d calls.', $locale, count($rendering['values']), count($this->calls)));
            }
            if ($locale !== 'en-US' && !in_array($locale, $this->english, true) && $this->translatedCount($locale) === 0) {
                throw new \RuntimeException($locale . ' has no translated entries.');
            }
        }
    }

    /** Calls whose rendering in this locale differs from en-US. */
    public function translatedCount(string $locale): int
    {
        $count = 0;
        $english = $this->renderings['en-US'];
        foreach ($this->renderings[$locale]['values'] as $index => $value) {
            if ($value !== $english['values'][$index] || ($this->renderings[$locale]['errors'][$index] ?? null) !== ($english['errors'][$index] ?? null)) {
                $count++;
            }
        }

        return $count;
    }

    /** @return Result */
    public function result(string $locale, string $function, int|string ...$arguments): array
    {
        $index = array_search([$function, $arguments], $this->calls, true);
        if (!is_int($index)) {
            throw new \OutOfBoundsException('Call is not in the corpus: ' . $function . json_encode($arguments));
        }

        $rendering = $this->renderings[$locale];

        return ['value' => $rendering['values'][$index], 'error' => $rendering['errors'][$index] ?? null, 'warnings' => $rendering['warnings'][$index] ?? []];
    }

    /** Removes a directory tree this class created, without following links. */
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

    private static function link(string $target, string $link): void
    {
        if (!symlink($target, $link)) {
            throw new \RuntimeException('Cannot link ' . $link);
        }
    }
}
