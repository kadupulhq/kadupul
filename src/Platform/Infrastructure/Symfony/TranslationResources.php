<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

use Symfony\Component\Translation\Translator;

/**
 * The translation resources Symfony loads for the legacy gettext catalogs,
 * listed in one place so every translator that needs them registers the same
 * set. Paths come from the shipped locale manifest only, never a request.
 *
 * The cacti domain holds each shipped .mo under its gettext identities (see
 * GettextMoLoader). Nothing translates through it yet: legacy __() still reads
 * the .mo through php-gettext, and the kernel translator does not register it.
 */
final class TranslationResources
{
    public const string CACTI_DOMAIN = 'cacti';

    public function __construct(private readonly string $projectDir) {}

    /**
     * Shipped catalogs from locales/catalogs.json, as xx-YY codes.
     *
     * @return list<string>
     */
    public static function shippedLocales(string $projectDir): array
    {
        $contents = file_get_contents($projectDir . '/locales/catalogs.json');
        if ($contents === false) {
            throw new \RuntimeException('Console language catalogue metadata is unavailable.');
        }
        $specification = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        $locales = is_array($specification) ? ($specification['translated_locales'] ?? null) : null;
        if (!is_array($locales) || !array_is_list($locales) || $locales === []) {
            throw new \RuntimeException('Console language catalogue metadata is invalid.');
        }
        $shipped = [];
        foreach ($locales as $locale) {
            if (!is_string($locale) || preg_match('/\A[a-z]{2}-[A-Z]{2}\z/D', $locale) !== 1) {
                throw new \RuntimeException('Console language catalogue identity is invalid.');
            }
            $shipped[] = $locale;
        }

        return $shipped;
    }

    /**
     * Symfony locales one shipped catalog serves. French is also served as
     * plain fr, because the module YAML domains are named *.fr.yaml.
     *
     * @return non-empty-list<string>
     */
    public static function symfonyLocales(string $locale): array
    {
        $symfony = str_replace('-', '_', $locale);

        return $locale === 'fr-FR' ? [$symfony, 'fr'] : [$symfony];
    }

    /**
     * One kadupul_mo resource for the cacti domain per Symfony locale.
     *
     * @return list<array{format: string, resource: string, locale: string, domain: string}>
     */
    public function cactiResources(): array
    {
        $resources = [];
        foreach (self::shippedLocales($this->projectDir) as $locale) {
            $file = $this->projectDir . '/locales/LC_MESSAGES/' . $locale . '.mo';
            foreach (self::symfonyLocales($locale) as $symfonyLocale) {
                $resources[] = ['format' => GettextMoLoader::FORMAT, 'resource' => $file, 'locale' => $symfonyLocale, 'domain' => self::CACTI_DOMAIN];
            }
        }

        return $resources;
    }

    public function registerInto(Translator $translator): void
    {
        $translator->addLoader(GettextMoLoader::FORMAT, new GettextMoLoader());
        foreach ($this->cactiResources() as $resource) {
            $translator->addResource($resource['format'], $resource['resource'], $resource['locale'], $resource['domain']);
        }
    }
}
