<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

use Kadupul\Platform\Application\Locale\KnownLocales;
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
        // Added resources must not reuse a catalogue cached before this domain
        // or the module gettext fallback existed.
        $options = $translator->getArgument(4);
        $options['cache_vary']['console_gettext'] = ['locales' => $locales, 'module_fallback' => 1];
        if (is_array($options)) {
            $resourceFiles = is_array($options['resource_files'] ?? null) ? $options['resource_files'] : [];
            foreach ($locales as $locale) {
                $symfonyLocale = $locale === 'fr-FR' ? 'fr' : str_replace('-', '_', $locale);
                $resourceFiles[$symfonyLocale] ??= [];
            }
            $options['resource_files'] = $resourceFiles;
        }
        $translator->replaceArgument(4, $options);
        foreach ($locales as $locale) {
            $file = $projectDir . '/locales/LC_MESSAGES/' . $locale . '.mo';
            $container->addResource(new FileResource($file));
            $translator->addMethodCall('addResource', ['mo', $file, str_replace('-', '_', $locale), 'console']);
            if ($locale === 'fr-FR') {
                $translator->addMethodCall('addResource', ['mo', $file, 'fr', 'console']);
            }
        }
        self::registerGettextFallback($container, $projectDir, $locales);
    }

    /**
     * Module YAML domains fall back to the shipped .po catalog of the same
     * language. Paths come from the shipped locale list only, never a request.
     *
     * @param list<string> $locales
     */
    private static function registerGettextFallback(ContainerBuilder $container, string $projectDir, array $locales): void
    {
        $translator = $container->findDefinition('translator.default');
        $sources = glob($projectDir . '/config/translations/*.en.yaml');
        if ($sources === false) {
            throw new \RuntimeException('Translation domains are unavailable.');
        }
        foreach ($locales as $locale) {
            $catalog = $projectDir . '/locales/po/' . $locale . '.po';
            $container->addResource(new FileResource($catalog));
            $symfonyLocale = $locale === 'fr-FR' ? 'fr' : str_replace('-', '_', $locale);
            foreach ($sources as $source) {
                $domain = basename($source, '.en.yaml');
                $resource = ['source' => $source, 'catalog' => $catalog, 'domain' => dirname($source) . '/' . $domain . '.' . $symfonyLocale . '.yaml'];
                $translator->addMethodCall('addResource', [GettextCatalogLoader::FORMAT, $resource, $symfonyLocale, $domain]);
            }
        }
    }

    public static function normalize(mixed $locale, string $projectDir): ?string
    {
        if (!is_string($locale) || preg_match('/\A([a-z]{2})(?:[-_]([a-z]{2}))?\z/iD', $locale, $match) !== 1) {
            return null;
        }
        $language = strtolower($match[1]);
        $region = isset($match[2]) ? strtoupper($match[2]) : '';
        $shipped = array_values(array_filter(self::locales($projectDir), static fn(string $supported): bool => str_starts_with($supported, $language . '-')));
        if ($language === 'en') {
            return ($region !== '' && $region !== 'US' && in_array('en-' . $region, $shipped, true)) ? 'en_' . $region : 'en';
        }
        if ($language === 'fr') {
            return ($region !== '' && $region !== 'FR' && in_array('fr-' . $region, $shipped, true)) ? 'fr_' . $region : 'fr';
        }
        if ($region !== '' && in_array($language . '-' . $region, $shipped, true)) {
            return $language . '_' . $region;
        }
        // Like legacy repair_locale(), a bare language or another region of a
        // language with one catalog uses that catalog. A bare multi-region
        // language (ar, es, pt, zh) picks the first shipped locale in
        // KnownLocales::ALL order, as the legacy locale list does; other
        // regions of those languages are unsupported rather than guessed.
        if (count($shipped) === 1 || ($region === '' && $shipped !== [])) {
            $chosen = null;
            foreach (array_keys(KnownLocales::ALL) as $known) {
                if (in_array($known, $shipped, true)) {
                    $chosen = $known;
                    break;
                }
            }
            $chosen ??= in_array($language . '-' . strtoupper($language), $shipped, true) ? $language . '-' . strtoupper($language) : $shipped[0];

            return str_replace('-', '_', $chosen);
        }

        return null;
    }

    /** @return list<string> */
    private static function locales(string $projectDir): array
    {
        return TranslationResources::shippedLocales($projectDir);
    }
}
