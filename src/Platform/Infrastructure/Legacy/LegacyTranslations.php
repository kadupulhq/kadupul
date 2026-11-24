<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\Platform\Infrastructure\Symfony\GettextMoLoader;
use Symfony\Component\Translation\Exception\InvalidResourceException;
use Symfony\Component\Translation\Exception\NotFoundResourceException;
use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\Translator;

/**
 * The text domains of one legacy request, loaded through Symfony Translation
 * for the CACTI_LANGUAGE_HANDLER_SYMFONY handler of
 * include/global_languages.php. The legacy page does not boot the kernel, so
 * this owns a Translator of its own.
 *
 * Lookups read the catalogue the Translator loads, not Translator::trans():
 * trans() returns '' for an empty id where gettext returns the header, and
 * selects plurals from "|" choices and Symfony's own rules where a catalog
 * carries NUL-joined forms and its own Plural-Forms.
 */
final class LegacyTranslations
{
    /** @var array<string, LegacyTextDomain> */
    private array $domains = [];

    /** @var array<string, string> */
    private array $failures = [];

    private bool $cached;

    /**
     * @param string $locale the legacy locale, as ll-CC
     * @param array<string, string> $catalogs compiled catalog (.mo) path by text domain
     * @param ?string $cacheDir where compiled catalogues are kept. Symfony
     *     includes them as PHP, so this must be a directory only the
     *     application writes to, never a shared temporary directory.
     */
    public function __construct(string $locale, array $catalogs, ?string $cacheDir)
    {
        $locale = str_replace('-', '_', $locale);
        if ($cacheDir !== null && !self::worthCompiling($cacheDir, $locale)) {
            $cacheDir = null;
        }
        $this->cached = $cacheDir !== null;
        try {
            $catalogue = self::catalogue($locale, $catalogs, $cacheDir);
        } catch (\Throwable) {
            // The cache directory is unusable or a catalog is unreadable.
            // Reading each catalog on its own tells the two apart, and one bad
            // plugin catalog then costs only its own domain.
            $this->cached = false;
            $catalogue = new MessageCatalogue($locale);
            $loader = new GettextMoLoader();
            foreach ($catalogs as $domain => $path) {
                try {
                    $catalogue->addCatalogue($loader->load($path, $locale, $domain));
                } catch (InvalidResourceException | NotFoundResourceException $exception) {
                    $this->failures[$domain] = $exception->getMessage();
                }
            }
        }
        foreach (array_keys($catalogs) as $domain) {
            if (!isset($this->failures[$domain])) {
                // Not getDomains(): the compiled cache drops a domain without messages.
                $this->domains[$domain] = new LegacyTextDomain($catalogue, $domain);
            }
        }
    }

    /**
     * The loaded domains, for the legacy $i18n global.
     *
     * @return array<string, LegacyTextDomain>
     */
    public function domains(): array
    {
        return $this->domains;
    }

    /**
     * Why a domain is missing from domains(); its text is shown in English.
     *
     * @return array<string, string>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /** False when the catalogs had to be read without the compiled cache. */
    public function cached(): bool
    {
        return $this->cached;
    }

    /**
     * False when the cache directory cannot take a new catalogue and holds
     * none for the locale. Trying it anyway would read every catalog, fail to
     * write and read them again, on every request of a read-only deployment.
     */
    private static function worthCompiling(string $cacheDir, string $locale): bool
    {
        if (is_dir($cacheDir)) {
            // Catalogues another user compiled can still be read.
            return is_writable($cacheDir) || (glob($cacheDir . '/catalogue.' . $locale . '.*.php') ?: []) !== [];
        }
        $ancestor = dirname($cacheDir);
        while (!is_dir($ancestor) && dirname($ancestor) !== $ancestor) {
            $ancestor = dirname($ancestor);
        }

        return is_writable($ancestor);
    }

    /** @param array<string, string> $catalogs */
    private static function catalogue(string $locale, array $catalogs, ?string $cacheDir): MessageCatalogueInterface
    {
        // Debug mode makes the cache check each catalog's modification time,
        // so a replaced .mo is recompiled. The cache file name carries the
        // locale and, through the vary argument, the domain to path map.
        $translator = new Translator($locale, null, $cacheDir, true, ['catalogs' => $catalogs]);
        $translator->addLoader(GettextMoLoader::FORMAT, new class implements LoaderInterface {
            #[\Override]
            public function load(mixed $resource, string $locale, string $domain = 'messages'): MessageCatalogue
            {
                try {
                    return (new GettextMoLoader())->load($resource, $locale, $domain);
                } catch (NotFoundResourceException $exception) {
                    // The Translator swallows a missing resource when the locale
                    // has a parent (de_DE has de), skips the catalogs after it
                    // and would compile that partial catalogue.
                    throw new InvalidResourceException($exception->getMessage(), 0, $exception);
                }
            }
        });
        foreach ($catalogs as $domain => $path) {
            $translator->addResource(GettextMoLoader::FORMAT, $path, $locale, $domain);
        }

        return $translator->getCatalogue($locale);
    }
}
