<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\AssetManagement\Domain;

/**
 * @phpstan-type Asset array{id: string, class: string, state: string, fields: array<string, string>, created_at: string}
 * @phpstan-type Identity array{id: string, source: string, key: string, asset_id: string, observed_at: string, ingested_at: string, first_seen_at: string, fields: array<string, string>}
 * @phpstan-type Dependency array{asset_id: string, depends_on: string}
 * @phpstan-type Review array{evidence: string, reviewed_at: string, actor_id: int, sample_size?: int, incorrect_merges?: int, missed_duplicates?: int}
 * @phpstan-type Policy array{scope?: string, steward?: string, freshness_seconds?: int, baseline?: array{asset_ids: list<string>, evidence: string, verified_at: string}, reviews?: array<string, Review>}
 * @phpstan-type Sources array<string, array{status: string, attempted_at: string, succeeded_at?: string, rows?: int}>
 * @phpstan-type Snapshot array{revision: int, policy: Policy, assets: array<string, Asset>, identities: array<string, Identity>, dependencies: array<string, Dependency>, sources: Sources}
 * @phpstan-type Result array{request_id: string, revision: int, asset_id?: string, observations?: int}
 * @phpstan-type History array{sequence: int, request_id: string, actor: string, action: string, evidence: string, occurred_at: string, changes: array<string, mixed>}
 */
final class AssetData
{
    /**
     * @return array<string, mixed> */
    public static function object(mixed $value): array
    {
        if (!is_array($value)) {
            throw new AssetError('invalid_fields');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new AssetError('invalid_fields');
            }
            $result[$key] = $item;
        }
        return $result;
    }

    /**
     * Returns the value when it is a string.
     *
     * @throws AssetError when stored data is not a string
     */
    public static function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new AssetError('invalid_storage');
        }
        return $value;
    }

    /**
     * Reads a stored non-negative integer from an integer or a canonical decimal string.
     *
     * @throws AssetError when the value is neither
     */
    public static function number(mixed $value): int
    {
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]{0,17})\z/D', $value) === 1) {
            return (int) $value;
        }
        return AssetFields::integer($value, PHP_INT_MAX);
    }

    /**
     * @param array<string, mixed> $data
     * @return Asset */
    public static function asset(array $data): array
    {
        $state = self::string($data['state'] ?? null);
        if (!in_array($state, AssetFields::STATES, true) || ($data['class'] ?? null) !== 'network_equipment') {
            throw new AssetError('invalid_storage');
        }
        return ['id' => AssetFields::id($data['id'] ?? null), 'class' => 'network_equipment', 'state' => $state, 'fields' => AssetFields::accepted(self::object($data['fields'] ?? null)), 'created_at' => self::timestamp($data['created_at'] ?? null)];
    }

    /**
     * @param array<string, mixed> $data
     * @return Identity */
    public static function identity(array $data): array
    {
        $id = self::string($data['id'] ?? null);
        if (preg_match('/\A[a-f0-9]{64}\z/D', $id) !== 1) {
            throw new AssetError('invalid_storage');
        }
        return ['id' => $id, 'source' => AssetFields::source($data['source'] ?? null), 'key' => AssetFields::text($data['key'] ?? null, 200, true), 'asset_id' => ($data['asset_id'] ?? null) === '' ? '' : AssetFields::id($data['asset_id'] ?? null), 'observed_at' => self::timestamp($data['observed_at'] ?? null), 'ingested_at' => self::timestamp($data['ingested_at'] ?? null), 'first_seen_at' => self::timestamp($data['first_seen_at'] ?? null), 'fields' => AssetFields::observed(self::object($data['fields'] ?? null))];
    }

    /**
     * @param array<string, mixed> $data
     * @return Policy */
    public static function policy(array $data): array
    {
        if ($data === []) {
            return [];
        }
        $result = ['scope' => AssetFields::text($data['scope'] ?? null, 200, true), 'steward' => AssetFields::text($data['steward'] ?? null, 200, true), 'freshness_seconds' => AssetFields::integer($data['freshness_seconds'] ?? null, 2592000)];
        if (isset($data['baseline'])) {
            $baseline = self::object($data['baseline']);
            $ids = $baseline['asset_ids'] ?? null;
            if (!is_array($ids) || !array_is_list($ids)) {
                throw new AssetError('invalid_storage');
            }
            $result['baseline'] = ['asset_ids' => array_map(AssetFields::id(...), $ids), 'evidence' => AssetFields::text($baseline['evidence'] ?? null, 500, true), 'verified_at' => self::timestamp($baseline['verified_at'] ?? null)];
        }
        if (isset($data['reviews'])) {
            foreach (self::object($data['reviews']) as $kind => $value) {
                $review = self::object($value);
                $record = ['evidence' => AssetFields::text($review['evidence'] ?? null, 500, true), 'reviewed_at' => self::timestamp($review['reviewed_at'] ?? null), 'actor_id' => AssetFields::integer($review['actor_id'] ?? null, 2147483647)];
                foreach (['sample_size', 'incorrect_merges', 'missed_duplicates'] as $field) {
                    if (isset($review[$field])) {
                        $record[$field] = AssetFields::integer($review[$field], 2000);
                    }
                }
                $result['reviews'][$kind] = $record;
            }
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return Sources */
    public static function sources(array $data): array
    {
        $sources = [];
        foreach ($data as $name => $value) {
            $source = self::object($value);
            $row = ['status' => AssetFields::text($source['status'] ?? null, 20, true), 'attempted_at' => self::timestamp($source['attempted_at'] ?? null)];
            if (isset($source['succeeded_at'])) {
                $row['succeeded_at'] = self::timestamp($source['succeeded_at']);
                $row['rows'] = AssetFields::integer($source['rows'] ?? null, 100);
            }
            $sources[$name] = $row;
        }
        return $sources;
    }

    /**
     * @param array<string, mixed> $data
     * @return Result */
    public static function result(array $data): array
    {
        $result = ['request_id' => AssetFields::id($data['request_id'] ?? null), 'revision' => AssetFields::integer($data['revision'] ?? null, 2147483647)];
        if (isset($data['asset_id'])) {
            $result['asset_id'] = AssetFields::id($data['asset_id']);
        }
        if (isset($data['observations'])) {
            $result['observations'] = AssetFields::integer($data['observations'], 100);
        }
        return $result;
    }

    /**
     * Returns the value when it is a UTC timestamp in the form 2026-01-31T12:00:00Z.
     *
     * @throws AssetError when stored data is not in that form
     */
    public static function timestamp(mixed $value): string
    {
        $value = self::string($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new AssetError('invalid_storage');
        }
        return $value;
    }
}
