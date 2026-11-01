<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Translation\Exception\InvalidResourceException;
use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\MessageCatalogue;

/**
 * Fills keys of a module's English YAML domain from the shipped gettext
 * catalog of the requested locale. Keys that the locale's own YAML domain
 * translates are left to it; keys neither has fall back to English.
 */
#[AutoconfigureTag('translation.loader', ['alias' => self::FORMAT])]
final class GettextCatalogLoader implements LoaderInterface
{
    public const string FORMAT = 'kadupul_gettext';

    /** @var array<string, GettextCatalog> */
    private array $catalogs = [];

    #[\Override]
    public function load(mixed $resource, string $locale, string $domain = 'messages'): MessageCatalogue
    {
        if (!is_array($resource) || !is_string($resource['source'] ?? null) || !is_string($resource['catalog'] ?? null) || !is_string($resource['domain'] ?? null)) {
            throw new InvalidResourceException('The gettext bridge resource is invalid.');
        }
        $yaml = new YamlFileLoader();
        $translated = is_file($resource['domain']) ? $yaml->load($resource['domain'], $locale, $domain)->all($domain) : [];
        $catalog = $this->catalogs[$resource['catalog']] ??= GettextCatalog::fromFile($resource['catalog']);
        $catalogue = new MessageCatalogue($locale);
        foreach (array_keys($yaml->load($resource['source'], 'en', $domain)->all($domain)) as $key) {
            $key = (string) $key;
            if (!isset($translated[$key]) && ($message = $catalog->translate($key)) !== null) {
                $catalogue->set($key, $message, $domain);
            }
        }
        $catalogue->addResource(new FileResource($resource['source']));
        $catalogue->addResource(new FileResource($resource['catalog']));
        if (is_file($resource['domain'])) {
            $catalogue->addResource(new FileResource($resource['domain']));
        }

        return $catalogue;
    }
}
