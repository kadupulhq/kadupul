<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\AssetManagement\Application;

use Kadupul\AssetManagement\Application\Port\AssetAccess;
use Kadupul\AssetManagement\Application\Port\AssetStore;

/**
 * @phpstan-import-type Policy from \Kadupul\AssetManagement\Domain\AssetData
 * @phpstan-import-type Identity from \Kadupul\AssetManagement\Domain\AssetData
 * @phpstan-import-type Dependency from \Kadupul\AssetManagement\Domain\AssetData
 * @phpstan-import-type Sources from \Kadupul\AssetManagement\Domain\AssetData
 * @phpstan-import-type Snapshot from \Kadupul\AssetManagement\Domain\AssetData
 * @phpstan-import-type Asset from \Kadupul\AssetManagement\Domain\AssetData
 * @phpstan-import-type History from \Kadupul\AssetManagement\Domain\AssetData
 */
final readonly class ViewAssets
{
    public function __construct(private AssetAccess $access, private AssetStore $store) {}

    /**
     * @phpstan-return array{revision: int, policy: Policy, assets: array<string, Asset>, identities: array<string, Identity>, dependencies: array<string, Dependency>, sources: Sources, metrics: array<string, int|bool>, conflicts: list<array<string, string>>, critical_gaps: array<string, Asset>, history: list<History>}
     */
    public function __invoke(int $before = PHP_INT_MAX): array
    {
        $this->access->requireActor();
        $state = $this->store->snapshot();
        $now = time();
        $fresh = [];
        $conflicts = [];
        $unlinked = [];
        foreach ($state['identities'] as $id => $identity) {
            $asset = $state['assets'][$identity['asset_id']] ?? null;
            if ($asset === null) {
                $unlinked[$id] = $identity;
                continue;
            }
            if (($state['sources'][$identity['source']]['status'] ?? '') === 'ok' && $now - (new \DateTimeImmutable($identity['observed_at']))->getTimestamp() <= ($state['policy']['freshness_seconds'] ?? 86400)) {
                $fresh[$asset['id']] = true;
            }
            foreach (['name', 'manufacturer', 'serial', 'location'] as $field) {
                if (isset($identity['fields'][$field]) && $identity['fields'][$field] !== $asset['fields'][$field]) {
                    $conflicts[] = ['identity_id' => $id, 'asset_id' => $asset['id'], 'field' => $field, 'accepted' => $asset['fields'][$field], 'observed' => $identity['fields'][$field], 'observed_at' => $identity['observed_at']];
                }
            }
        }
        $active = array_filter($state['assets'], static fn(array $asset): bool => $asset['state'] === 'active');
        $owned = array_filter($active, static fn(array $asset): bool => $asset['fields']['owner'] !== '' && $asset['fields']['custodian'] !== '');
        $criticalGaps = array_filter($active, static fn(array $asset): bool => $asset['fields']['criticality'] === 'critical' && ($asset['fields']['recovery_plan'] === '' || $asset['fields']['support_until'] === '' || $asset['fields']['support_until'] < gmdate('Y-m-d')));
        $baseline = $state['policy']['baseline']['asset_ids'] ?? [];
        $reviews = $state['policy']['reviews'] ?? [];
        $metrics = [
            'registered' => count($state['assets']), 'active' => count($active), 'owned_active' => count($owned),
            'fresh_active' => count(array_intersect_key($active, $fresh)), 'unlinked' => count($unlinked),
            'conflicts' => count($conflicts), 'critical_gaps' => count($criticalGaps),
            'baseline_total' => count($baseline), 'baseline_present' => count(array_intersect($baseline, array_keys($state['assets']))),
            'oldest_unlinked_days' => $unlinked === [] ? 0 : (int) floor(max(array_map(static fn(array $identity): int => $now - (new \DateTimeImmutable($identity['first_seen_at']))->getTimestamp(), $unlinked)) / 86400),
            'discrepancy_review_due' => !isset($reviews['discrepancies']) || $now - (new \DateTimeImmutable($reviews['discrepancies']['reviewed_at']))->getTimestamp() > 7 * 86400,
            'lifecycle_review_due' => !isset($reviews['lifecycle']) || $now - (new \DateTimeImmutable($reviews['lifecycle']['reviewed_at']))->getTimestamp() > 30 * 86400,
        ];
        return $state + ['metrics' => $metrics, 'conflicts' => $conflicts, 'critical_gaps' => $criticalGaps, 'history' => $this->store->history($before, 50)];
    }
}
