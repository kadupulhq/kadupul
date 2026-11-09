<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Locale;

/**
 * Selects a request's locale. Legacy pages and Symfony routes both call this,
 * so the rules live here once.
 *
 * Precedence:
 *
 * 1. i18n_language_support '0' shows English.
 * 2. The installation's forced language, else the `language` request parameter.
 * 3. sess_user_language, when it is a known locale exactly.
 * 4. The saved user_language.
 * 5. Accept-Language, unless i18n_auto_detection is off.
 * 6. i18n_default_language, then the settings table default, then en-US.
 *
 * Every value except Accept-Language goes through repair(), the legacy
 * repair_locale() rule, and stops the search once it names a known locale,
 * even one without a catalog: a saved zh-HK shows English, not the browser's
 * language. Accept-Language is an RFC 9110 weighted list instead; its first
 * range, by weight, that names a locale with a catalog wins.
 *
 * Settings keep their legacy loose comparisons, so '2.0' is strict mode and
 * ' 1' enables detection, exactly as the legacy == tests read them.
 *
 * Kept free of other classes except its input, its result and KnownLocales:
 * the legacy bootstrap requires these files before the Composer autoloader.
 */
final class LocaleResolver
{
    public const SOURCE_LOCALE = 'en-US';

    public function resolve(LocaleInput $input): LocaleResolution
    {
        if (self::disables($input->languageSupport)) {
            return LocaleResolution::disabled();
        }

        $requested = self::filled($input->forcedLanguage) ? $input->forcedLanguage : ($input->requestedLanguage)();
        $requestLanguage = null;
        if (self::filled($requested)) {
            $requestLanguage = $this->repair($requested);
        }

        $requestDecides = $requestLanguage !== null && isset(KnownLocales::ALL[$requestLanguage]);
        if ($requestDecides) {
            $selected = $requestLanguage;
        } elseif (self::filled($input->sessionLanguage) && isset(KnownLocales::ALL[$input->sessionLanguage])) {
            $selected = $input->sessionLanguage;
        } else {
            $selected = $this->apply($input, ($input->savedLanguage)());
        }

        $locale = $selected === false ? self::SOURCE_LOCALE : $selected;

        return new LocaleResolution(
            false,
            $locale,
            $selected,
            $requestDecides,
            $requestLanguage,
            !($input->hasCatalog)($locale),
        );
    }

    /**
     * The legacy apply_locale(): $language when it names a known locale,
     * otherwise Accept-Language and then the default language.
     */
    public function apply(LocaleInput $input, string|false|null $language): string|false
    {
        $locale = null;
        if (is_string($language) && $language !== '') {
            $candidate = $this->repair($language);
            if (isset(KnownLocales::ALL[$candidate])) {
                $locale = $candidate;
            }
        }

        if ($locale === null && $input->acceptLanguage !== null && self::detects($input->autoDetection)) {
            $locale = $this->preferred($input);
        }

        if ($locale === null) {
            $candidate = $this->repair($input->defaultLanguage);
            if ($candidate === '') {
                $candidate = $this->repair(($input->settingDefaultLanguage)());
            }
            if (isset(KnownLocales::ALL[$candidate])) {
                $locale = $candidate;
            }
        }

        return $locale ?? false;
    }

    /**
     * The legacy repair_locale(): an exact known locale after `_` becomes `-`,
     * else the first listed locale with the same first two characters, else ''.
     * An empty value means en-US.
     */
    public function repair(?string $language): string
    {
        if ($language === null || $language === '') {
            return self::SOURCE_LOCALE;
        }

        $locale = str_replace('_', '-', $language);
        if (isset(KnownLocales::ALL[$locale])) {
            return $locale;
        }

        $wanted = substr($language, 0, 2);
        foreach (array_keys(KnownLocales::ALL) as $known) {
            if (substr($known, 0, 2) === $wanted) {
                return $known;
            }
        }

        return '';
    }

    /** Legacy `i18n_language_support == 2`. */
    public function strictMode(LocaleInput $input): bool
    {
        return self::numericEquals($input->languageSupport, 2.0);
    }

    /**
     * Strict mode shows English unless every installed plugin ships a catalog
     * for the locale.
     */
    public function requiresStrictFallback(LocaleInput $input, int $plugins, int $pluginCatalogs): bool
    {
        return $this->strictMode($input) && $plugins > 0 && $plugins !== $pluginCatalogs;
    }

    /**
     * The first range of the Accept-Language list, highest weight first and
     * then in order, that names a locale shipping a catalog. English needs no
     * catalog. Empty members are ignored (RFC 9110 section 5.6.1), as are
     * ranges weighted q=0 or with an invalid weight.
     */
    private function preferred(LocaleInput $input): ?string
    {
        $ranges = [];
        foreach (explode(',', (string) $input->acceptLanguage) as $position => $member) {
            $parameters = explode(';', $member);
            $range = trim(array_shift($parameters));
            $weight = 1.0;
            foreach ($parameters as $parameter) {
                [$name, $value] = array_pad(explode('=', $parameter, 2), 2, '');
                if (strtolower(trim($name)) === 'q') {
                    $value = trim($value);
                    $weight = preg_match('/\A(?:0(?:\.\d{0,3})?|1(?:\.0{0,3})?)\z/', $value) === 1 ? (float) $value : 0.0;
                }
            }
            if ($range !== '' && $weight > 0.0) {
                $ranges[] = [$range, $weight, $position];
            }
        }
        usort($ranges, static fn(array $a, array $b): int => [$b[1], $a[2]] <=> [$a[1], $b[2]]);

        foreach ($ranges as [$range]) {
            // Language tags are case-insensitive; the locale table is not.
            $tag = preg_match('/\A([A-Za-z]{2})[-_]([A-Za-z]{2})\z/', $range, $match) === 1
                ? strtolower($match[1]) . '-' . strtoupper($match[2])
                : strtolower($range);
            $locale = $this->repair($tag);
            if (isset(KnownLocales::ALL[$locale]) && (str_starts_with($locale, 'en-') || ($input->hasCatalog)($locale))) {
                return $locale;
            }
        }

        return null;
    }

    /** Legacy `!$value && $value != ''`: only '0' among strings. */
    private static function disables(?string $value): bool
    {
        return $value === '0';
    }

    /** Legacy `$value == '' || $value == '1'`. */
    private static function detects(?string $value): bool
    {
        return $value === null || $value === '' || self::numericEquals($value, 1.0);
    }

    /** Legacy `!empty($value)` for a string. */
    private static function filled(?string $value): bool
    {
        return $value !== null && $value !== '' && $value !== '0';
    }

    /** Legacy `$value == $number` for a numeric-string setting. */
    private static function numericEquals(?string $value, float $number): bool
    {
        return $value !== null && is_numeric($value) && (float) $value === $number;
    }
}
