<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\AssetManagement\Infrastructure\Symfony;

use Kadupul\AssetManagement\Domain\AssetError;
use Kadupul\AssetManagement\Domain\AssetData;
use Kadupul\AssetManagement\Domain\AssetFields;

final class AssetFormInput
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function command(array $input): array
    {
        $action = AssetFields::text($input['action'] ?? null, 30, true);
        $command = ['action' => $action, 'request_id' => AssetFields::id($input['request_id'] ?? null), 'revision' => self::integer($input['revision'] ?? null)];
        $keys = match ($action) {
            'configure' => ['scope', 'steward', 'freshness_seconds'],
            'create' => ['fields'], 'edit' => ['asset_id', 'fields', 'evidence'],
            'transition' => ['asset_id', 'state', 'evidence'], 'link' => ['identity_id', 'asset_id', 'evidence'],
            'accept' => ['identity_id', 'fields', 'evidence'],
            'dependency' => ['asset_id', 'depends_on', 'remove', 'evidence'],
            'baseline' => ['asset_ids', 'evidence'],
            'review' => ['kind', 'evidence', 'sample_size', 'incorrect_merges', 'missed_duplicates'],
            'import' => ['source', 'observations'], 'source_failure' => ['source'],
            'monitoring' => ['device_ids'],
            default => throw new AssetError('invalid_command'),
        };
        if (array_diff(array_keys($input), [...$keys, 'action', 'request_id', 'revision', '_token']) !== []) {
            throw new AssetError('invalid_command');
        }
        foreach ($keys as $key) {
            $value = $input[$key] ?? null;
            $command[$key] = match ($key) {
                'freshness_seconds', 'sample_size', 'incorrect_merges', 'missed_duplicates' => self::integer($value),
                'remove' => match ($value) {
                    '0' => false, '1' => true, default => throw new AssetError('invalid_fields')
                },
                'asset_id' => $action === 'link' && $value === '' ? '' : AssetFields::id($value),
                'depends_on' => AssetFields::id($value),
                'fields' => self::fields($value, $action),
                'asset_ids' => array_map(AssetFields::id(...), self::words($value, 17000)),
                'device_ids' => array_map(self::integer(...), self::words($value, 1100)),
                'observations' => self::observations($value),
                'source' => AssetFields::source($value),
                default => AssetFields::text($value, $key === 'evidence' ? 500 : 200, true),
            };
        }
        return $command;
    }

    /** @return list<string> */
    private static function words(mixed $value, int $limit): array
    {
        $words = preg_split('/\s+/', AssetFields::text($value, $limit, true));
        if ($words === false) {
            throw new AssetError('invalid_fields');
        }
        return $words;
    }

    private static function integer(mixed $value): int
    {
        if (!is_string($value) || preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $value) !== 1 || (int) $value > 2147483646) {
            throw new AssetError('invalid_fields');
        }
        return (int) $value;
    }

    /**
     * @return array<string, string>|list<string>
     */
    private static function fields(mixed $value, string $action): array
    {
        if (!is_array($value) || $value === []) {
            throw new AssetError('invalid_fields');
        }
        if ($action === 'accept') {
            if (!array_is_list($value) || count($value) > 4) {
                throw new AssetError('invalid_fields');
            }
            foreach ($value as $field) {
                if (!is_string($field) || !in_array($field, ['name', 'manufacturer', 'serial', 'location'], true)) {
                    throw new AssetError('invalid_fields');
                }
            }
            return array_map(AssetData::string(...), $value);
        }
        $fields = AssetData::object($value);
        if ($action === 'edit') {
            if (array_diff(array_keys($fields), array_keys(AssetFields::DEFAULTS)) !== []) {
                throw new AssetError('invalid_fields');
            }
            // Keep omitted values omitted. The application validates the full
            // record after merging this patch with its locked current revision.
            return array_map(static fn(mixed $field): string => AssetFields::text($field, 500), $fields);
        }
        return AssetFields::accepted($fields);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function observations(mixed $value): array
    {
        if (!is_string($value) || strlen($value) > 450000) {
            throw new AssetError('invalid_observation');
        }
        try {
            $rows = json_decode($value, true, 12, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AssetError('invalid_observation');
        }
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 100) {
            throw new AssetError('invalid_observation');
        }
        foreach ($rows as $row) {
            if (!is_array($row) || array_diff(array_keys($row), ['key', 'observed_at', 'fields']) !== [] || !is_array($row['fields'] ?? null)) {
                throw new AssetError('invalid_observation');
            }
            AssetFields::externalKey($row['key'] ?? null);
            AssetFields::text($row['observed_at'] ?? null, 20, true);
            AssetFields::observed(AssetData::object($row['fields']));
        }
        return array_map(AssetData::object(...), $rows);
    }
}
