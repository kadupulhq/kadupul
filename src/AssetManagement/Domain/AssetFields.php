<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\AssetManagement\Domain;

/**
 * Validation rules and defaults for asset register fields. Every check throws an AssetError instead of coercing bad input.
 */
final class AssetFields
{
    public const array DEFAULTS = [
        'name' => '', 'owner' => '', 'custodian' => '', 'manufacturer' => '', 'serial' => '',
        'location' => '', 'service' => '', 'criticality' => 'standard', 'recovery_plan' => '',
        'redundancy' => '', 'support_until' => '', 'contract' => '', 'purchase_cost_minor' => '',
        'currency' => '', 'replacement_lead_days' => '', 'maintenance_due' => '', 'maintenance_plan' => '',
    ];
    public const array OBSERVED = ['name', 'manufacturer', 'serial', 'location', 'software', 'address'];
    public const array STATES = ['planned', 'received', 'active', 'stored', 'retired', 'disposed'];

    /**
     * @param array<string, mixed> $fields
     * @return array<string, string>
     */
    public static function accepted(array $fields): array
    {
        if (array_diff(array_keys($fields), array_keys(self::DEFAULTS)) !== []) {
            throw new AssetError('invalid_fields');
        }
        $result = self::DEFAULTS;
        foreach ($fields as $key => $value) {
            $result[$key] = self::text($value, 500);
        }
        if ($result['name'] === '' || !in_array($result['criticality'], ['standard', 'important', 'critical'], true)) {
            throw new AssetError('invalid_fields');
        }
        foreach (['support_until', 'maintenance_due'] as $key) {
            self::date($result[$key]);
        }
        foreach (['purchase_cost_minor' => 999999999999, 'replacement_lead_days' => 36500] as $key => $max) {
            if ($result[$key] !== '' && (preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $result[$key]) !== 1 || strlen($result[$key]) > 12 || (int) $result[$key] > $max)) {
                throw new AssetError('invalid_fields');
            }
        }
        if (($result['purchase_cost_minor'] === '') !== ($result['currency'] === '') || ($result['currency'] !== '' && preg_match('/\A[A-Z]{3}\z/D', $result['currency']) !== 1)) {
            throw new AssetError('invalid_fields');
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, string>
     */
    public static function observed(array $fields): array
    {
        if ($fields === [] || array_diff(array_keys($fields), self::OBSERVED) !== []) {
            throw new AssetError('invalid_observation');
        }
        $result = [];
        foreach ($fields as $key => $value) {
            $result[$key] = self::text($value, 500);
        }
        ksort($result);
        return $result;
    }

    /**
     * Returns the trimmed text after checking that it is valid UTF-8, free of control characters and at most $max characters long.
     *
     * @throws AssetError when the value is not acceptable text, or is blank and $required is set
     */
    public static function text(mixed $value, int $max = 200, bool $required = false): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) === 1 || mb_strlen($value, 'UTF-8') > $max) {
            throw new AssetError('invalid_fields');
        }
        $value = trim($value);
        if ($required && $value === '') {
            throw new AssetError('invalid_fields');
        }
        return $value;
    }

    /**
     * Returns the value when it is a 32-character lowercase hexadecimal id.
     *
     * @throws AssetError when it is not
     */
    public static function id(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{32}\z/D', $value) !== 1) {
            throw new AssetError('invalid_id');
        }
        return $value;
    }

    /**
     * Returns the name of an observation source.
     *
     * @throws AssetError when it is not a valid name, or has leading or trailing whitespace
     */
    public static function source(mixed $value): string
    {
        $value = self::externalKey($value, 120);
        if (preg_match('/\A[a-zA-Z][a-zA-Z0-9._:\/-]*\z/D', $value) !== 1) {
            throw new AssetError('invalid_observation');
        }
        return $value;
    }

    /**
     * Returns a source's own key for an item, which must not need trimming.
     *
     * @throws AssetError when the key is blank, too long or has leading or trailing whitespace
     */
    public static function externalKey(mixed $value, int $limit = 200): string
    {
        $key = self::text($value, $limit, true);
        if ($key !== $value) {
            throw new AssetError('invalid_observation');
        }
        return $key;
    }

    /**
     * Returns the value when it is an integer from 0 to $max.
     *
     * @throws AssetError when it is not
     */
    public static function integer(mixed $value, int $max): int
    {
        if (!is_int($value) || $value < 0 || $value > $max) {
            throw new AssetError('invalid_fields');
        }
        return $value;
    }

    /**
     * Checks that the value is empty or a calendar date as YYYY-MM-DD.
     *
     * @throws AssetError when it is not
     */
    private static function date(string $value): void
    {
        if ($value === '') {
            return;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value || $value < '1970-01-01' || $value > '9999-12-31') {
            throw new AssetError('invalid_fields');
        }
    }
}
