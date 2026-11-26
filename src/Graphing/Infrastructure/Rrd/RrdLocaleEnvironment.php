<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Rrd;

/**
 * Resolves and applies validated LC_TIME / LC_NUMERIC / LANG environment
 * variables for RRDtool and Rondi child processes.
 *
 * POSIX precedence is LC_ALL > LC_TIME / LC_NUMERIC > LANG. Setting LC_TIME
 * with LC_ALL unset and LC_NUMERIC pinned to C ensures localized calendar
 * labels work on both glibc RRDtool and pure-Rust Rondi while preserving
 * dot-decimal numeric parsing and formatting under comma-decimal locales.
 */
final class RrdLocaleEnvironment
{
    public const string NEUTRAL_LOCALE = 'C';
    public const string NUMERIC_LOCALE = 'C';

    /** @var list<string> */
    private const array MANAGED_VARIABLES = ['LC_ALL', 'LC_NUMERIC', 'LC_TIME', 'LANG'];

    /** @var list<array<string, string|false>> */
    private array $savedStack = [];

    /**
     * Validate and convert a Kadupul BCP-47 or POSIX locale tag into a POSIX
     * UTF-8 locale tag (e.g. de-DE -> de_DE.UTF-8) for LC_TIME, or 'C' when
     * neutral, empty, invalid, or outside the supplied allowlist.
     *
     * @param list<string>|null $allowedLocales Canonical BCP-47 tags (e.g. KnownLocales keys)
     */
    public static function normalizeTimeLocale(?string $locale, ?array $allowedLocales = null): string
    {
        if ($locale === null || $locale === '') {
            return self::NEUTRAL_LOCALE;
        }

        if ($locale === 'C' || $locale === 'POSIX' || $locale === 'C.UTF-8' || $locale === 'en') {
            return self::NEUTRAL_LOCALE;
        }

        if (preg_match('/\A([a-z]{2})[-_]([A-Z]{2})(?:\.UTF-8)?\z/D', $locale, $matches) !== 1) {
            return self::NEUTRAL_LOCALE;
        }

        $bcp47 = $matches[1] . '-' . $matches[2];
        if ($allowedLocales !== null && !in_array($bcp47, $allowedLocales, true)) {
            return self::NEUTRAL_LOCALE;
        }

        return $matches[1] . '_' . $matches[2] . '.UTF-8';
    }

    /**
     * Build child-process environment overrides for Symfony Process or proc_open.
     *
     * Unsets LC_ALL (false) so it cannot shadow LC_TIME or LC_NUMERIC, pins
     * LC_NUMERIC to C, and sets LC_TIME and LANG to the validated time locale.
     *
     * @param list<string>|null $allowedLocales
     * @return array{LC_ALL: false, LC_NUMERIC: string, LC_TIME: string, LANG: string}
     */
    public static function processOverrides(?string $locale, ?array $allowedLocales = null): array
    {
        $timeLocale = self::normalizeTimeLocale($locale, $allowedLocales);

        return [
            'LC_ALL' => false,
            'LC_NUMERIC' => self::NUMERIC_LOCALE,
            'LC_TIME' => $timeLocale,
            'LANG' => $timeLocale,
        ];
    }

    /**
     * Apply the validated locale environment to the current PHP process before
     * spawning a legacy RRDtool or Rondi child process, pushing the previous
     * values onto an internal stack for restore().
     *
     * @param list<string>|null $allowedLocales
     */
    public function apply(?string $locale, ?array $allowedLocales = null): string
    {
        $snapshot = [];
        foreach (self::MANAGED_VARIABLES as $variable) {
            $snapshot[$variable] = getenv($variable);
        }
        $this->savedStack[] = $snapshot;

        $overrides = self::processOverrides($locale, $allowedLocales);
        foreach ($overrides as $variable => $value) {
            if ($value === false) {
                putenv($variable);
            } else {
                putenv($variable . '=' . $value);
            }
        }

        return $overrides['LC_TIME'];
    }

    /** Restore the most recent environment snapshot pushed by apply(). */
    public function restore(): void
    {
        $snapshot = array_pop($this->savedStack);
        if ($snapshot === null) {
            return;
        }

        foreach ($snapshot as $variable => $previous) {
            if ($previous === false) {
                putenv($variable);
            } else {
                putenv($variable . '=' . $previous);
            }
        }
    }
}
