<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

use Kadupul\Platform\Application\Locale\KnownLocales;
use Kadupul\Platform\Application\Locale\LocaleResolver;
use Twig\Attribute\AsTwigFunction;

/**
 * Exposes HTML text direction ('ltr' or 'rtl') for Twig templates from KnownLocales.
 */
final readonly class LocaleDirection
{
    public function __construct(
        private LocaleResolver $resolver = new LocaleResolver(),
    ) {}

    #[AsTwigFunction('locale_direction')]
    public function direction(string|\Stringable|null $locale): string
    {
        $raw = trim((string) $locale);
        if ($raw === '') {
            return 'ltr';
        }

        $normalized = str_replace('_', '-', $raw);
        if (preg_match('/\A([A-Za-z]{2})-([A-Za-z]{2})\z/', $normalized, $match) === 1) {
            $normalized = strtolower($match[1]) . '-' . strtoupper($match[2]);
        }

        if (isset(KnownLocales::ALL[$normalized])) {
            return KnownLocales::ALL[$normalized]['direction'];
        }

        $repaired = $this->resolver->repair(strtolower($normalized));
        if ($repaired !== '' && isset(KnownLocales::ALL[$repaired])) {
            return KnownLocales::ALL[$repaired]['direction'];
        }

        return 'ltr';
    }
}
