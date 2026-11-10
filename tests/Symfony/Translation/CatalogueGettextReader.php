<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Translation;

use Kadupul\Platform\Infrastructure\Symfony\GettextMoLoader;
use Kadupul\Platform\Infrastructure\Symfony\PluralFormsExpression;
use Kadupul\Platform\Infrastructure\Symfony\TranslationResources;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\Translator;

/**
 * Answers the three gettext_reader calls include/global_languages.php makes
 * (translate, ngettext, select_string) from a Symfony catalogue, so the
 * legacy helpers can render the corpus over the new loader. It holds only the
 * lookup contract a Symfony handler must keep; the helpers around it, and the
 * php-gettext output it is compared with, are the legacy ones.
 */
final class CatalogueGettextReader
{
    private readonly PluralFormsExpression $plural;

    public function __construct(private readonly MessageCatalogueInterface $catalogue, private readonly string $domain)
    {
        $this->plural = PluralFormsExpression::fromCatalogHeader($this->defined(''));
    }

    /**
     * Replaces each loaded legacy text domain: cacti through the registrar and
     * a Translator compiling into $cacheDir, plugin domains straight from
     * their .mo through the loader.
     *
     * @param array<string, object> $readers
     * @param array<string, array{path2catalogue: string}> $textDomains
     * @return array<string, self>
     */
    public static function replace(array $readers, array $textDomains, string $root, string $cacheDir, string $locale): array
    {
        $replaced = [];
        foreach (array_keys($readers) as $domain) {
            if ($domain === TranslationResources::CACTI_DOMAIN) {
                $symfonyLocale = TranslationResources::symfonyLocales($locale)[0];
                $translator = new Translator($symfonyLocale, null, $cacheDir, false);
                (new TranslationResources($root))->registerInto($translator);
                $catalogue = $translator->getCatalogue($symfonyLocale);
            } else {
                $catalogue = (new GettextMoLoader())->load($textDomains[$domain]['path2catalogue'], $locale, $domain);
            }
            $replaced[$domain] = new self($catalogue, $domain);
        }

        return $replaced;
    }

    public function translate(string $string): string
    {
        return $this->catalogue->defines($string, $this->domain) ? $this->catalogue->get($string, $this->domain) : $string;
    }

    public function ngettext(string $single, string $plural, mixed $number): ?string
    {
        $select = $this->select_string($number);
        $key = $single . "\0" . $plural;
        if (!$this->catalogue->defines($key, $this->domain)) {
            return $number != 1 ? $plural : $single;
        }

        return explode("\0", $this->catalogue->get($key, $this->domain))[$select] ?? null;
    }

    /** php-gettext refuses a count that is not an int, and so does this. */
    public function select_string(mixed $n): int
    {
        if (!is_int($n)) {
            throw new \InvalidArgumentException('Select_string only accepts integers: ' . (is_scalar($n) ? (string) $n : get_debug_type($n)));
        }

        return $this->plural->index($n);
    }

    private function defined(string $id): string
    {
        return $this->catalogue->defines($id, $this->domain) ? $this->catalogue->get($id, $this->domain) : '';
    }
}
