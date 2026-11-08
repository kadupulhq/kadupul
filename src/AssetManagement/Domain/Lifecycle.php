<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\AssetManagement\Domain;

/**
 * @phpstan-import-type Snapshot from AssetData
 * @phpstan-import-type Asset from AssetData
 */
final class Lifecycle
{
    /**
     * @param Snapshot $register
     * @return Asset
     */
    public static function transition(array $register, string $id, string $state): array
    {
        $transitions = ['planned' => ['received'], 'received' => ['active', 'stored', 'retired'], 'active' => ['stored', 'retired'], 'stored' => ['active', 'retired'], 'retired' => ['stored', 'disposed'], 'disposed' => []];
        if (!in_array($state, $transitions[$register['assets'][$id]['state']], true)) {
            throw new AssetError('invalid_transition');
        }
        if (in_array($state, ['retired', 'disposed'], true)) {
            foreach ($register['dependencies'] as $dependency) {
                if ($dependency['depends_on'] === $id && !in_array($register['assets'][$dependency['asset_id']]['state'], ['retired', 'disposed'], true)) {
                    throw new AssetError('dependent_assets');
                }
            }
        }
        $asset = $register['assets'][$id] ?? throw new AssetError('not_found');
        $asset['state'] = $state;
        self::requireOwnership($asset);
        if ($state === 'active') {
            foreach ($register['dependencies'] as $edge) {
                if ($edge['asset_id'] === $id && in_array($register['assets'][$edge['depends_on']]['state'], ['retired', 'disposed'], true)) {
                    throw new AssetError('invalid_dependency');
                }
            }
        }
        return $asset;
    }

    /**
     * @param Asset $asset
     */
    public static function requireOwnership(array $asset): void
    {
        if ($asset['state'] === 'active' && ($asset['fields']['owner'] === '' || $asset['fields']['custodian'] === '' || $asset['fields']['service'] === '')) {
            throw new AssetError('ownership_required');
        }
    }

}
