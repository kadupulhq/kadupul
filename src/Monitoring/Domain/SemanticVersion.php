<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/** A pack version, ordered by Semantic Versioning 2.0.0 precedence. */
final readonly class SemanticVersion
{
    public const PATTERN = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?(\+[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?$/D';

    /** @param list<string> $preRelease */
    private function __construct(
        public int $major,
        public int $minor,
        public int $patch,
        private array $preRelease,
        private string $text,
    ) {}

    public static function parse(string $text): self
    {
        if (preg_match(self::PATTERN, $text, $m) !== 1 || strlen($m[1]) > 9 || strlen($m[2]) > 9 || strlen($m[3]) > 9) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a semantic version such as 1.4.0.', $text));
        }
        $preRelease = isset($m[4]) && $m[4] !== '' ? explode('.', substr($m[4], 1)) : [];

        return new self((int) $m[1], (int) $m[2], (int) $m[3], $preRelease, $text);
    }

    public function toString(): string
    {
        return $this->text;
    }

    public function compare(self $other): int
    {
        $core = [$this->major, $this->minor, $this->patch] <=> [$other->major, $other->minor, $other->patch];
        if ($core !== 0) {
            return $core;
        }
        if ($this->preRelease === [] || $other->preRelease === []) {
            // A release outranks any of its pre-releases.
            return ($this->preRelease === []) <=> ($other->preRelease === []);
        }
        foreach ($this->preRelease as $i => $identifier) {
            if (!isset($other->preRelease[$i])) {
                return 1;
            }
            $order = self::compareIdentifier($identifier, $other->preRelease[$i]);
            if ($order !== 0) {
                return $order;
            }
        }

        return count($this->preRelease) < count($other->preRelease) ? -1 : 0;
    }

    private static function compareIdentifier(string $a, string $b): int
    {
        $aNumeric = ctype_digit($a);
        $bNumeric = ctype_digit($b);
        if ($aNumeric && $bNumeric) {
            return strlen($a) <=> strlen($b) ?: strcmp($a, $b) <=> 0;
        }
        if ($aNumeric !== $bNumeric) {
            return $aNumeric ? -1 : 1;
        }

        return strcmp($a, $b) <=> 0;
    }
}
