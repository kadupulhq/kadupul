<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Domain;

final readonly class AutomationTemplate
{
    public const int DESCRIPTION_LENGTH = 255;
    public const int NAME_LENGTH = 255;
    public const int OID_LENGTH = 60;

    /** @param ?string $deviceTemplate null when the device template no longer exists */
    public function __construct(
        public int $id,
        public int $hostTemplate,
        public ?string $deviceTemplate,
        public int $availabilityMethod,
        public string $sysDescr,
        public string $sysName,
        public string $sysOid,
        public int $sequence,
        public string $revision,
    ) {}

    /**
     * The legacy form stored the description through FILTER_SANITIZE_FULL_SPECIAL_CHARS
     * and discovery matches that stored text, so the same encoding is kept. It does
     * not encode existing entities again, so an unchanged form saves the same value.
     */
    public static function storedDescription(string $entered): string
    {
        // Invalid UTF-8 would encode to an empty string and silently clear the match.
        $stored = self::singleLine($entered) ? (string) filter_var($entered, FILTER_SANITIZE_FULL_SPECIAL_CHARS) : null;
        if ($stored === null || mb_strlen($stored) > self::DESCRIPTION_LENGTH) {
            throw new \InvalidArgumentException('Enter a System Description Match of one line and at most 255 characters, counting each special character as its HTML entity.');
        }
        return $stored;
    }

    public static function validateMatches(string $sysName, string $sysOid): void
    {
        if (!self::singleLine($sysName) || mb_strlen($sysName) > self::NAME_LENGTH) {
            throw new \InvalidArgumentException('Enter a System Name Match of one line and at most 255 characters.');
        }
        if (!self::singleLine($sysOid) || mb_strlen($sysOid) > self::OID_LENGTH) {
            throw new \InvalidArgumentException('Enter a System OID Match of one line and at most 60 characters.');
        }
    }

    public static function validateAvailability(int $method): void
    {
        if (!isset(AvailabilityMethods::LABELS[$method])) {
            throw new \InvalidArgumentException('Choose a valid Availability Method.');
        }
    }

    private static function singleLine(string $value): bool
    {
        return preg_match('//u', $value) === 1 && preg_match('/[\x00\r\n]/', $value) !== 1;
    }
}
