<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\Platform\Infrastructure\Symfony\PluralFormsExpression;
use Symfony\Component\Translation\MessageCatalogueInterface;

/**
 * One gettext text domain as include/global_languages.php reads it: the two
 * calls it makes on a php-gettext reader, answered from the messages of a
 * Symfony catalogue (see GettextMoLoader for their identities).
 *
 * A contextual message is looked up as "context\x04msgid", which is the key
 * __x() and __xn() already build.
 */
final class LegacyTextDomain
{
    private readonly PluralFormsExpression $plural;

    /**
     * Messages are read from the catalogue on every call:
     * MessageCatalogue::all() would copy the whole domain into this process.
     */
    public function __construct(private readonly MessageCatalogueInterface $catalogue, private readonly string $domain)
    {
        $this->plural = PluralFormsExpression::fromCatalogHeader($catalogue->defines('', $domain) ? $catalogue->get('', $domain) : '');
    }

    /**
     * The translation of a message, or the argument itself when the catalog
     * has none. The argument is untyped because legacy callers pass whatever
     * they hold; php-gettext reads null as the empty msgid.
     */
    public function translate(mixed $text): mixed
    {
        $text ??= '';
        if (!is_string($text) && !is_int($text)) {
            return $text;
        }

        return $this->catalogue->defines((string) $text, $this->domain) ? $this->catalogue->get((string) $text, $this->domain) : $text;
    }

    /**
     * The plural form the catalog's Plural-Forms rule selects for $number, or
     * the English choice when the catalog lacks the message. Null when the
     * translation has fewer forms than the rule selects.
     *
     * @throws \InvalidArgumentException for a count that is not an int, as php-gettext does
     */
    public function ngettext(string $singular, string $plural, mixed $number): ?string
    {
        if (!is_int($number)) {
            throw new \InvalidArgumentException('Select_string only accepts integers: ' . (is_scalar($number) ? (string) $number : get_debug_type($number)));
        }
        $key = $singular . "\0" . $plural;
        if (!$this->catalogue->defines($key, $this->domain)) {
            return $number !== 1 ? $plural : $singular;
        }

        return explode("\0", $this->catalogue->get($key, $this->domain))[$this->plural->index($number)] ?? null;
    }
}
