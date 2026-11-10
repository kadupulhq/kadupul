<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Translation\Exception\InvalidResourceException;
use Symfony\Component\Translation\Exception\NotFoundResourceException;
use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\MessageCatalogue;

/**
 * Loads a compiled gettext catalog (.mo) without changing any entry, so a
 * lookup sees exactly what php-gettext reads for legacy __() today.
 *
 * Message ids are the .mo originals: "context\x04msgid" for a contextual
 * entry and "msgid\x00msgid_plural" for a plural one. A plural translation
 * keeps every form, empty ones included, joined with "\x00". Symfony's own
 * mo and po loaders join plural forms with "|", which several msgids contain,
 * and drop the context and the header.
 *
 * The header entry (msgid "") is kept as a message: legacy __('') returns it,
 * and it carries the Plural-Forms rule. Symfony's compiled catalogue cache
 * keeps messages but not metadata, so the rule is read from that message
 * (PluralFormsExpression::fromCatalogHeader), and the loader checks here that
 * the rule parses.
 */
final class GettextMoLoader implements LoaderInterface
{
    public const string FORMAT = 'kadupul_mo';

    private const int MAX_BYTES = 16 * 1024 * 1024;

    #[\Override]
    public function load(mixed $resource, string $locale, string $domain = 'messages'): MessageCatalogue
    {
        if (!is_string($resource) || $resource === '' || str_contains($resource, "\0")) {
            throw new InvalidResourceException('The gettext catalog resource is invalid.');
        }
        if (!is_file($resource)) {
            throw new NotFoundResourceException('The gettext catalog does not exist.');
        }
        $size = filesize($resource);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new InvalidResourceException('The gettext catalog is unreadable or too large.');
        }
        $bytes = file_get_contents($resource);
        if ($bytes === false || strlen($bytes) !== $size) {
            throw new InvalidResourceException('The gettext catalog is unreadable.');
        }
        $catalogue = new MessageCatalogue($locale);
        $catalogue->add(self::entries($bytes), $domain);
        try {
            PluralFormsExpression::fromCatalogHeader($catalogue->defines('', $domain) ? $catalogue->get('', $domain) : '');
        } catch (\InvalidArgumentException $exception) {
            throw new InvalidResourceException('The gettext catalog has an invalid Plural-Forms rule.', 0, $exception);
        }
        $catalogue->addResource(new FileResource($resource));

        return $catalogue;
    }

    /**
     * The original => translation pairs of a .mo file, in file order.
     *
     * @return array<string, string>
     */
    private static function entries(string $bytes): array
    {
        $length = strlen($bytes);
        if ($length < 20) {
            throw new InvalidResourceException('The gettext catalog is truncated.');
        }
        $order = match (substr($bytes, 0, 4)) {
            "\xde\x12\x04\x95" => 'V',
            "\x95\x04\x12\xde" => 'N',
            default => throw new InvalidResourceException('The file is not a gettext catalog.'),
        };
        [$revision, $count, $originals, $translations] = self::integers($bytes, $order, 4, 4);
        // GNU gettext writes major revision 0 or 1; the minor revision is ignored.
        if ($revision >> 16 > 1) {
            throw new InvalidResourceException('The gettext catalog revision is unsupported.');
        }
        if ($count > intdiv($length, 16) || $originals > $length - $count * 8 || $translations > $length - $count * 8) {
            throw new InvalidResourceException('The gettext catalog tables are out of bounds.');
        }
        $originalTable = self::integers($bytes, $order, $originals, $count * 2);
        $translationTable = self::integers($bytes, $order, $translations, $count * 2);
        $entries = [];
        for ($index = 0; $index < $count; $index++) {
            $original = self::text($bytes, $originalTable[$index * 2], $originalTable[$index * 2 + 1]);
            if (array_key_exists($original, $entries)) {
                throw new InvalidResourceException('The gettext catalog repeats a message.');
            }
            $entries[$original] = self::text($bytes, $translationTable[$index * 2], $translationTable[$index * 2 + 1]);
        }

        return $entries;
    }

    /** @return list<int> */
    private static function integers(string $bytes, string $order, int $offset, int $count): array
    {
        if ($count === 0) {
            return [];
        }
        $values = unpack($order . $count, $bytes, $offset);
        if ($values === false || count($values) !== $count) {
            throw new InvalidResourceException('The gettext catalog is truncated.');
        }
        $integers = [];
        foreach ($values as $value) {
            if (!is_int($value)) {
                throw new InvalidResourceException('The gettext catalog is truncated.');
            }
            $integers[] = $value;
        }

        return $integers;
    }

    /** A string of the .mo string pool; its length excludes the terminating NUL. */
    private static function text(string $bytes, int $length, int $offset): string
    {
        if ($offset > strlen($bytes) || $length > strlen($bytes) - $offset) {
            throw new InvalidResourceException('A gettext catalog string is out of bounds.');
        }

        return substr($bytes, $offset, $length);
    }
}
