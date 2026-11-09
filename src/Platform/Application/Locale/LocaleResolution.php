<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Locale;

/**
 * The locale one request selected, and what the legacy bootstrap does with it.
 *
 * English is shown when $disabled or $catalogMissing is true, or when
 * LocaleResolver::requiresStrictFallback() says so. $locale is still the
 * selected locale then, as the legacy $cacti_locale global keeps it.
 */
final readonly class LocaleResolution
{
    /**
     * @param string $locale the selected known locale, en-US when none was
     * @param string|false $selected the selected locale, false when no source named a known one
     * @param bool $requested a forced or requested language decided, so the legacy page persists it
     * @param ?string $requestLanguage the repaired value the legacy `language` parameter takes, null to leave it
     */
    public function __construct(
        public bool $disabled,
        public string $locale,
        public string|false $selected,
        public bool $requested,
        public ?string $requestLanguage,
        public bool $catalogMissing,
    ) {}

    public static function disabled(): self
    {
        return new self(true, LocaleResolver::SOURCE_LOCALE, false, false, null, true);
    }

    public function showsEnglish(): bool
    {
        return $this->disabled || $this->catalogMissing;
    }
}
