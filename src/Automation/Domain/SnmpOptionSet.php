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
