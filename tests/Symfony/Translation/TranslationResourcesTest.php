<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Translation;

use Kadupul\Platform\Infrastructure\Symfony\GettextMoLoader;
use Kadupul\Platform\Infrastructure\Symfony\TranslationResources;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;

final class TranslationResourcesTest extends TestCase
{
    public function testRegistersOneCactiCatalogPerShippedLocale(): void
    {
        $root = RenderedCorpus::root();
        $manifest = json_decode((string) file_get_contents($root . '/locales/catalogs.json'), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertSame($manifest['translated_locales'], TranslationResources::shippedLocales($root));
        $resources = (new TranslationResources($root))->cactiResources();
        self::assertSame(24, count($resources));
        $locales = [];
        foreach ($resources as $resource) {
            self::assertSame(GettextMoLoader::FORMAT, $resource['format']);
            self::assertSame(TranslationResources::CACTI_DOMAIN, $resource['domain']);
            self::assertFileExists($resource['resource']);
            $locales[$resource['locale']] = basename($resource['resource']);
        }
        self::assertSame('fr-FR.mo', $locales['fr']);
        self::assertSame('fr-FR.mo', $locales['fr_FR']);
        self::assertSame('pt-BR.mo', $locales['pt_BR']);
        self::assertArrayNotHasKey('en', $locales);
        self::assertArrayNotHasKey('en_US', $locales);

        $translator = new Translator('en');
        (new TranslationResources($root))->registerInto($translator);
        self::assertSame('Zielsysteme', $translator->trans('Devices', [], TranslationResources::CACTI_DOMAIN, 'de_DE'));
        self::assertSame('Löschen', $translator->trans("dropdown action\x04Delete", [], TranslationResources::CACTI_DOMAIN, 'de_DE'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function manifests(): iterable
    {
        yield 'unreadable' => ['', 'unavailable'];
        yield 'not an object' => ['"de-DE"', 'invalid'];
        yield 'no locales' => ['{"translated_locales": []}', 'invalid'];
        yield 'a map' => ['{"translated_locales": {"a": "de-DE"}}', 'invalid'];
        yield 'a path' => ['{"translated_locales": ["../de-DE"]}', 'identity is invalid'];
        yield 'lower-case region' => ['{"translated_locales": ["de-de"]}', 'identity is invalid'];
        yield 'not a string' => ['{"translated_locales": [7]}', 'identity is invalid'];
    }

    #[DataProvider('manifests')]
    public function testRefusesAnUnusableManifest(string $manifest, string $message): void
    {
        $root = sys_get_temp_dir() . '/kadupul-manifest-' . bin2hex(random_bytes(8));
        mkdir($root . '/locales', 0700, true);
        if ($manifest !== '') {
            file_put_contents($root . '/locales/catalogs.json', $manifest);
        }
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage($message);
            set_error_handler(static fn(): bool => true, E_WARNING);
            try {
                TranslationResources::shippedLocales($root);
            } finally {
                restore_error_handler();
            }
        } finally {
            @unlink($root . '/locales/catalogs.json');
            rmdir($root . '/locales');
            rmdir($root);
        }
    }
}
