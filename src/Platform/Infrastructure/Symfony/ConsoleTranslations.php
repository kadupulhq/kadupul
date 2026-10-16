<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Symfony owns translation; the shipped gettext catalogues retain their identities. */
final class ConsoleTranslations
{
    public static function register(ContainerBuilder $container, string $projectDir): void
    {
        $translator = $container->findDefinition('translator.default');
        $container->addResource(new FileResource($projectDir . '/locales/catalogs.json'));
        $locales = self::locales($projectDir);
        // Added resources must not reuse a catalogue cached before this domain existed.
        $options = $translator->getArgument(4);
        $options['cache_vary']['console_gettext'] = $locales;
        $translator->replaceArgument(4, $options);
        foreach ($locales as $locale) {
            $file = $projectDir . '/locales/LC_MESSAGES/' . $locale . '.mo';
            $container->addResource(new FileResource($file));
            $translator->addMethodCall('addResource', ['mo', $file, str_replace('-', '_', $locale), 'console']);
            if ($locale === 'fr-FR') {
                $translator->addMethodCall('addResource', ['mo', $file, 'fr', 'console']);
            }
        }
    }

    public static function normalize(mixed $locale, string $projectDir): ?string
    {
        if (!is_string($locale) || preg_match('/\A[a-z]{2}(?:[-_][a-z]{2})?\z/iD', $locale) !== 1) {
            return null;
        }
        $candidate = strtolower(str_replace('_', '-', $locale));
        if ($candidate === 'en' || str_starts_with($candidate, 'en-')) {
            return 'en';
        }
        if ($candidate === 'fr' || str_starts_with($candidate, 'fr-')) {
            return 'fr';
        }
        foreach (self::locales($projectDir) as $supported) {
            if (strtolower($supported) === $candidate || !str_contains($candidate, '-') && str_starts_with(strtolower($supported), $candidate . '-')) {
                return str_replace('-', '_', $supported);
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function locales(string $projectDir): array
    {
        $contents = file_get_contents($projectDir . '/locales/catalogs.json');
        if ($contents === false) {
            throw new \RuntimeException('Console language catalogue metadata is unavailable.');
        }
        $specification = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        $locales = $specification['translated_locales'] ?? null;
        if (!is_array($locales) || !array_is_list($locales) || $locales === []) {
            throw new \RuntimeException('Console language catalogue metadata is invalid.');
        }
        foreach ($locales as $locale) {
            if (!is_string($locale) || preg_match('/\A[a-z]{2}-[A-Z]{2}\z/D', $locale) !== 1) {
                throw new \RuntimeException('Console language catalogue identity is invalid.');
            }
        }

        return $locales;
    }
}
