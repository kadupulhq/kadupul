<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Locale;

/**
 * What one request knows about its viewer's language.
 *
 * Settings are the raw stored strings, null when unset. The legacy bootstrap
 * reads the request parameter, the saved user setting and the settings table
 * default only when an earlier source has not decided, and checks catalogs on
 * disk, so those four are lookups the resolver calls when it needs them. Callers without a source pass
 * a lookup that returns null.
 */
final readonly class LocaleInput
{
    /**
     * @param ?string $languageSupport i18n_language_support: '0' disables, '2' is strict
     * @param ?string $autoDetection i18n_auto_detection
     * @param ?string $defaultLanguage i18n_default_language
     * @param \Closure(): ?string $settingDefaultLanguage the settings table default for i18n_default_language
     * @param ?string $forcedLanguage the installation's $i18n_force_language
     * @param \Closure(): ?string $requestedLanguage the legacy `language` request parameter
     * @param ?string $sessionLanguage sess_user_language
     * @param \Closure(): (string|false|null) $savedLanguage the viewer's saved user_language
     * @param ?string $acceptLanguage the Accept-Language header, null when absent
     * @param \Closure(string): bool $hasCatalog whether a known locale ships a catalog
     */
    public function __construct(
        public ?string $languageSupport,
        public ?string $autoDetection,
        public ?string $defaultLanguage,
        public \Closure $settingDefaultLanguage,
        public ?string $forcedLanguage,
        public \Closure $requestedLanguage,
        public ?string $sessionLanguage,
        public \Closure $savedLanguage,
        public ?string $acceptLanguage,
        public \Closure $hasCatalog,
    ) {}
}
