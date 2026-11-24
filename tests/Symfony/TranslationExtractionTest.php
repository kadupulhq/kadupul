<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Yaml\Yaml;

/**
 * Runs Symfony's own message extractor over src/ and templates/, as
 * `bin/console translation:extract` does, and requires that every key the code
 * uses has an English entry in config/translations/<domain>.en.yaml.
 *
 * The reverse is not checked. Label tables (src/Navigation/Contract/*) and shared
 * templates pass keys through variables, which the extractor cannot see, so an
 * English entry that is not extracted may still be used.
 */
final class TranslationExtractionTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /**
     * Shared templates that translate with a domain variable. The extractor files
     * their keys under "_undefined"; each caller's domain must hold them.
     */
    private const SHARED_TEMPLATES = ['_action_confirm.html.twig', '_item_delete.html.twig', '_list.html.twig'];

    /** @var array<string, array<string, string>>|null domain => key => key, as the extractor found them */
    private static ?array $extracted = null;

    /** @var array<string, array<string, string>> */
    private static array $yaml = [];

    /** @var array<string, array<string, list<string>>> domain => key => source files */
    private static array $sources = [];

    /** @return array<string, array<string, string>> */
    private static function extracted(): array
    {
        if (self::$extracted !== null) {
            return self::$extracted;
        }
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertTrue($container->has('translation.extractor'), 'The translation extractor service is not available.');
            $extractor = $container->get('translation.extractor');
            $catalogue = new MessageCatalogue('en');
            // The paths translation:extract scans: the project src/ and the default views path.
            foreach (['/src', '/templates'] as $path) {
                $extractor->extract(self::ROOT . $path, $catalogue);
            }
        } finally {
            $kernel->shutdown();
        }
        $found = [];
        foreach ($catalogue->all() as $domain => $messages) {
            foreach (array_keys($messages) as $key) {
                $key = (string) $key;
                $found[$domain][$key] = $key;
                foreach ((array) ($catalogue->getMetadata($key, $domain)['sources'] ?? []) as $source) {
                    self::$sources[$domain][$key][] = preg_replace('/:\d+\z/', '', (string) $source);
                }
            }
        }
        self::assertNotSame([], $found, 'The extractor found no messages.');

        return self::$extracted = $found;
    }

    /** @return array<string, string>|null null when the domain has no English YAML catalog */
    private static function yaml(string $domain): ?array
    {
        $file = self::ROOT . '/config/translations/' . $domain . '.en.yaml';
        if (!is_file($file)) {
            return null;
        }
        if (!isset(self::$yaml[$domain])) {
            $messages = Yaml::parseFile($file);
            self::assertIsArray($messages, $file);
            self::$yaml[$domain] = array_combine(array_map('strval', array_keys($messages)), array_map('strval', $messages));
        }

        return self::$yaml[$domain];
    }

    /** @return iterable<string, array{string}> */
    public static function yamlDomains(): iterable
    {
        $files = glob(self::ROOT . '/config/translations/*.en.yaml') ?: [];
        self::assertNotSame([], $files, 'No translation catalogs were discovered.');
        foreach ($files as $file) {
            $domain = basename($file, '.en.yaml');
            yield $domain => [$domain];
        }
    }

    #[DataProvider('yamlDomains')]
    public function testEveryExtractedKeyHasAnEnglishEntry(string $domain): void
    {
        $missing = array_values(array_diff(array_keys(self::extracted()[$domain] ?? []), array_keys(self::yaml($domain) ?? [])));

        self::assertSame([], $missing, $domain . '.en.yaml lacks keys the code uses; add them to every locale file of the domain');
    }

    public function testDomainsWithoutAnEnglishCatalogAreAccountedFor(): void
    {
        // "console" is served from the shipped gettext catalogs through the module
        // fallback (ConsoleTranslations), not from YAML, so it is out of scope here.
        // "messages" (no domain given) and "_undefined" (a domain variable) are resolved below.
        $handled = ['console', 'messages', '_undefined'];
        foreach ($handled as $domain) {
            self::assertNull(self::yaml($domain), $domain . ' now has a YAML catalog; check it like the other domains');
        }
        $unknown = array_values(array_diff(array_keys(self::extracted()), $handled, array_map(static fn(array $row): string => $row[0], iterator_to_array(self::yamlDomains(), false))));

        self::assertSame([], $unknown, 'Extracted domains with no English YAML catalog');
    }

    public function testMessagesDomainKeysResolveToARealDomainThatHoldsThem(): void
    {
        // Twig has no source metadata and uses the template default domain, so a key
        // that reaches "messages" from a template has no domain at all; PHP calls
        // must pass a domain, directly or through a class constant the extractor does
        // not evaluate.
        $problems = [];
        foreach (self::extracted()['messages'] ?? [] as $key) {
            $files = array_values(array_unique(self::$sources['messages'][$key] ?? []));
            if ($files === []) {
                $problems[] = '"' . $key . '" is translated with no domain (a template without trans_default_domain, or a call with no domain)';
                continue;
            }
            $calls = 0;
            foreach ($files as $file) {
                foreach (self::phpCallDomains($file, $key) as $domain) {
                    ++$calls;
                    if ($domain === null) {
                        $problems[] = '"' . $key . '" in ' . self::relative($file) . ' passes no resolvable domain';
                    } elseif (!isset(self::yaml($domain)[$key])) {
                        $problems[] = '"' . $key . '" in ' . self::relative($file) . ' resolves to domain "' . $domain . '", whose en.yaml lacks it';
                    }
                }
            }
            if ($calls === 0) {
                $problems[] = '"' . $key . '" was extracted from ' . implode(', ', array_map(self::relative(...), $files)) . ' but no trans() call with that key was found there';
            }
        }

        self::assertSame([], $problems);
    }

    public function testUndefinedDomainKeysAreHeldByEveryCallerOfTheSharedTemplates(): void
    {
        $keys = [];
        $problems = [];
        foreach (self::SHARED_TEMPLATES as $shared) {
            $source = (string) file_get_contents(self::ROOT . '/templates/' . $shared);
            self::assertGreaterThan(0, preg_match_all("/'((?:[^'\\\\]|\\\\.)+)'\\|trans\\(\\{[^}]*\\},\\s*domain\\)/", $source, $matches), $shared . ' translates nothing with the domain variable');
            $callers = 0;
            foreach (self::callerDomains($shared, $source) as $caller => $uses) {
                ++$callers;
                foreach ($uses as [$domain, $used]) {
                    foreach ($used as $key) {
                        if (!isset(self::yaml($domain)[$key])) {
                            $problems[] = $caller . ' shows "' . $key . '" through ' . $shared . ' in domain "' . $domain . '", whose en.yaml lacks it';
                        }
                    }
                }
            }
            self::assertGreaterThan(0, $callers, 'No template uses ' . $shared);
            array_push($keys, ...$matches[1]);
        }
        $extracted = array_keys(self::extracted()['_undefined'] ?? []);
        sort($keys);
        $keys = array_values(array_unique($keys));
        sort($extracted);

        self::assertSame($keys, $extracted, 'The shared templates and the "_undefined" extraction disagree; list the new shared template');
        self::assertSame([], $problems);
    }

    /**
     * Calls to trans() with $key as the message in one PHP file, each reduced to its
     * domain: the literal, a class constant of that file, or null when absent or unknown.
     *
     * @return list<string|null>
     */
    private static function phpCallDomains(string $file, string $key): array
    {
        $tokens = array_values(array_filter(\PhpToken::tokenize((string) file_get_contents($file)), static fn(\PhpToken $token): bool => !$token->isIgnorable()));
        $domains = [];
        foreach ($tokens as $index => $token) {
            if (!$token->is(T_CONSTANT_ENCAPSED_STRING) || self::literal($token->text) !== $key) {
                continue;
            }
            if (($tokens[$index - 1]->text ?? '') !== '(' || ($tokens[$index - 2]->text ?? '') !== 'trans') {
                continue;
            }
            $arguments = [[]];
            $depth = 0;
            for ($cursor = $index; isset($tokens[$cursor]); ++$cursor) {
                $text = $tokens[$cursor]->text;
                if (in_array($text, ['(', '[', '{'], true) || $tokens[$cursor]->is(T_CURLY_OPEN)) {
                    ++$depth;
                } elseif (in_array($text, [')', ']', '}'], true)) {
                    if ($depth === 0) {
                        break;
                    }
                    --$depth;
                }
                if ($text === ',' && $depth === 0) {
                    $arguments[] = [];
                    continue;
                }
                $arguments[array_key_last($arguments)][] = $tokens[$cursor];
            }
            // Drop a trailing comma's empty argument.
            if (end($arguments) === []) {
                array_pop($arguments);
            }
            $domains[] = isset($arguments[2]) ? self::domainOf($arguments[2], $tokens) : null;
        }

        return $domains;
    }

    /**
     * @param list<\PhpToken> $argument
     * @param list<\PhpToken> $tokens
     */
    private static function domainOf(array $argument, array $tokens): ?string
    {
        $texts = array_map(static fn(\PhpToken $token): string => $token->text, $argument);
        if (count($argument) === 1 && $argument[0]->is(T_CONSTANT_ENCAPSED_STRING)) {
            return self::literal($texts[0]);
        }
        if (count($argument) === 3 && in_array($texts[0], ['self', 'static'], true) && $texts[1] === '::') {
            foreach ($tokens as $index => $token) {
                if ($token->text === $texts[2] && ($tokens[$index + 1]->text ?? '') === '=' && ($tokens[$index + 2] ?? null)?->is(T_CONSTANT_ENCAPSED_STRING)) {
                    return self::literal($tokens[$index + 2]->text);
                }
            }
        }

        return null;
    }

    private static function literal(string $text): string
    {
        $quote = $text[0];
        $body = substr($text, 1, -1);

        return $quote === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $body) : stripcslashes($body);
    }

    /**
     * Each template that extends or imports the shared template, with the domains it
     * gives it and the keys that domain must then hold.
     *
     * @return iterable<string, list<array{string, list<string>}>>
     */
    private static function callerDomains(string $shared, string $source): iterable
    {
        $keyed = [];
        if ($shared === '_list.html.twig') {
            preg_match_all('/\{%\s*macro\s+(\w+)\(([^)]*)\)\s*%\}(.*?)\{%\s*endmacro\s*%\}/s', $source, $macros, PREG_SET_ORDER);
            foreach ($macros as [, $name, $parameters, $body]) {
                $position = array_search('domain', array_map(static fn(string $parameter): string => trim(explode('=', $parameter)[0]), explode(',', $parameters)), true);
                preg_match_all("/'((?:[^'\\\\]|\\\\.)+)'\\|trans\\(\\{[^}]*\\},\\s*domain\\)/", $body, $found);
                if ($found[1] !== []) {
                    self::assertIsInt($position, 'Macro ' . $name . ' has no domain parameter');
                    $keyed[$name] = [$position, $found[1]];
                }
            }
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/templates', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!str_ends_with($file->getFilename(), '.twig') || $file->getFilename() === $shared) {
                continue;
            }
            $template = (string) file_get_contents($file->getPathname());
            $name = substr($file->getPathname(), strlen(self::ROOT) + 1);
            $quoted = preg_quote($shared, '/');
            $uses = [];
            if (preg_match('/\{%\s*extends\s+\'' . $quoted . '\'/', $template) === 1) {
                self::assertSame(1, preg_match("/\\{%\\s*set\\s+domain\\s*=\\s*'([a-z_]+)'\\s*%\\}/", $template, $domain), $name . ' extends ' . $shared . ' without setting domain to a literal');
                preg_match_all("/'((?:[^'\\\\]|\\\\.)+)'\\|trans\\(\\{[^}]*\\},\\s*domain\\)/", (string) file_get_contents(self::ROOT . '/templates/' . $shared), $found);
                $uses[] = [$domain[1], $found[1]];
            }
            if (preg_match_all('/\{%\s*import\s+\'' . $quoted . '\'\s+as\s+(\w+)\s*%\}/', $template, $aliases) > 0) {
                foreach (array_unique($aliases[1]) as $alias) {
                    foreach ($keyed as $macro => [$position, $keys]) {
                        preg_match_all('/\b' . $alias . '\.' . $macro . '\(/', $template, $calls, PREG_OFFSET_CAPTURE);
                        foreach ($calls[0] as [$match, $offset]) {
                            $arguments = self::twigArguments($template, $offset + strlen($match));
                            self::assertSame(1, preg_match("/\\A\\s*'([a-z_]+)'\\s*\\z/", $arguments[$position] ?? '', $domain), $name . ': ' . $alias . '.' . $macro . '() needs a literal domain argument');
                            $uses[] = [$domain[1], $keys];
                        }
                    }
                }
            }
            if ($uses !== []) {
                yield $name => $uses;
            }
        }
    }

    /** @return list<string> top-level arguments of the Twig call whose opening parenthesis precedes $start */
    private static function twigArguments(string $template, int $start): array
    {
        $arguments = [''];
        $depth = 0;
        $quote = null;
        for ($cursor = $start, $length = strlen($template); $cursor < $length; ++$cursor) {
            $character = $template[$cursor];
            if ($quote !== null) {
                if ($character === '\\') {
                    $arguments[array_key_last($arguments)] .= $character . ($template[++$cursor] ?? '');
                    continue;
                }
                $quote = $character === $quote ? null : $quote;
            } elseif ($character === "'" || $character === '"') {
                $quote = $character;
            } elseif (in_array($character, ['(', '[', '{'], true)) {
                ++$depth;
            } elseif (in_array($character, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    return $arguments;
                }
                --$depth;
            } elseif ($character === ',' && $depth === 0) {
                $arguments[] = '';
                continue;
            }
            $arguments[array_key_last($arguments)] .= $character;
        }
        self::fail('Unterminated Twig call');
    }

    private static function relative(string $path): string
    {
        $root = realpath(self::ROOT);

        return $root !== false && str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
    }
}
