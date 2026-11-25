<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Domain;

/** A named, ordered group of SNMP settings that discovery tries in sequence. */
final readonly class SnmpOptionSet
{
    public const int NAME_LENGTH = 100;
    /** The Duplicate action's default name format; every <name> stands for the original's name. */
    public const string NAME_FORMAT = '<name> (1)';
    /** The legacy field accepted 255 characters; the name it produces is checked against the column. */
    public const int NAME_FORMAT_LENGTH = 255;

    public function __construct(
        public int $id,
        public string $name,
        public string $revision,
    ) {}

    /** The legacy form required a name and stored it as entered, up to the column width. */
    public static function validateName(string $name): void
    {
        if ($name === '' || !mb_check_encoding($name, 'UTF-8') || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Enter a name for this SNMP Option Set.');
        }
        if (mb_strlen($name, 'UTF-8') > self::NAME_LENGTH) {
            throw new \InvalidArgumentException('The name can be at most 100 characters.');
        }
    }

    /**
     * The name a copy of $name gets from $format. The token is the literal
     * <name> in every locale: the legacy default translated it, so a translated
     * console offered a format its own replacement never matched.
     */
    public static function copyName(string $format, string $name): string
    {
        if (!mb_check_encoding($format, 'UTF-8') || mb_strlen($format, 'UTF-8') > self::NAME_FORMAT_LENGTH || preg_match('/[\x00-\x1f\x7f]/', $format) === 1) {
            throw new \InvalidArgumentException('Enter a valid name format.');
        }
        $copy = str_replace('<name>', $name, $format);
        if ($copy === '' || mb_strlen($copy, 'UTF-8') > self::NAME_LENGTH) {
            throw new \InvalidArgumentException('The name format must give every copy a name of 1 to 100 characters.');
        }
        return $copy;
    }

    /** @param array<string, mixed> $row id and name */
    public static function revisionOf(array $row): string
    {
        $id = $row['id'] ?? null;
        $name = $row['name'] ?? null;
        if (!is_numeric($id) || !is_string($name)) {
            throw new \UnexpectedValueException('The SNMP Option Set row is incomplete.');
        }
        return hash('sha256', json_encode([(int) $id, $name], JSON_THROW_ON_ERROR));
    }
}
