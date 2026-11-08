<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\AssetManagement\Application;

use Kadupul\AssetManagement\Application\Port\AssetAccess;
use Kadupul\AssetManagement\Application\Port\AssetStore;
use Kadupul\AssetManagement\Application\Port\AssetWriter;
use Kadupul\AssetManagement\Application\Port\MonitoringInventory;
use Kadupul\AssetManagement\Domain\AssetError;
use Kadupul\AssetManagement\Domain\AssetData;
use Kadupul\AssetManagement\Domain\Lifecycle;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\AssetManagement\Domain\AssetFields;

/**
 * @phpstan-import-type Snapshot from \Kadupul\AssetManagement\Domain\AssetData
 * @phpstan-import-type Asset from \Kadupul\AssetManagement\Domain\AssetData
 * @phpstan-import-type Result from \Kadupul\AssetManagement\Domain\AssetData
 */
final readonly class AssetRegistry implements AssetWriter
{
    public const int MAX_ASSETS = 500;
    public const int MAX_IDENTITIES = 2000;
    public const int MAX_BATCH = 100;

    public function __construct(private AssetAccess $access, private AssetStore $store, private MonitoringInventory $monitoring, private AuditTrail $audit) {}

    /**
     * @param array<string, mixed> $command
     * @return Result
     */
    public function apply(array $command): array
    {
        $actor = null;
        $decision = AuditEvent::DENIED;
        $outcome = AuditEvent::DENIED;
        $correlation = is_string($command['request_id'] ?? null) && preg_match('/\A[a-f0-9]{32}\z/D', $command['request_id']) === 1 ? $command['request_id'] : bin2hex(random_bytes(16));
        try {
            $actor = $this->access->requireActor();
            $decision = AuditEvent::ALLOWED;
            $outcome = AuditEvent::FAILED;
            $result = $this->execute($command);
            $outcome = AuditEvent::SUCCEEDED;
            return $result;
        } catch (AssetError $error) {
            if (in_array($error->reason, ['denied', 'unauthenticated', 'disabled'], true)) {
                $decision = AuditEvent::DENIED;
                $outcome = AuditEvent::DENIED;
            }
            throw $error;
        } finally {
            try {
                $this->audit->record(new AuditEvent($correlation, $actor?->id, 'asset.command', 'asset_register', '1', $decision, $outcome, gmdate('Y-m-d\TH:i:s\Z')));
            } catch (\Throwable) {
                // The transactional journal is mandatory. A secondary log failure
                // must not turn a committed receipt into a reported failed write.
            }
        }
    }

    /**
     * @param array<string, mixed> $command
     * @return Result
     */
    private function execute(array $command): array
    {
        $actor = $this->access->requireActor();
        $requestId = AssetFields::id($command['request_id'] ?? null);
        $revision = AssetFields::integer($command['revision'] ?? null, 2147483646);
        $action = AssetFields::text($command['action'] ?? null, 40, true);
        $allowed = [
            'configure' => ['scope', 'steward', 'freshness_seconds'],
            'create' => ['fields'], 'edit' => ['asset_id', 'fields', 'evidence'],
            'transition' => ['asset_id', 'state', 'evidence'],
            'link' => ['identity_id', 'asset_id', 'evidence'],
            'accept' => ['identity_id', 'fields', 'evidence'],
            'dependency' => ['asset_id', 'depends_on', 'remove', 'evidence'],
            'import' => ['source', 'observations'], 'source_failure' => ['source'],
            'monitoring' => ['device_ids'],
            'baseline' => ['asset_ids', 'evidence'],
            'review' => ['kind', 'evidence', 'sample_size', 'incorrect_merges', 'missed_duplicates'],
        ];
        if (!isset($allowed[$action]) || array_diff(array_keys($command), ['action', 'request_id', 'revision', ...$allowed[$action]]) !== []) {
            throw new AssetError('invalid_command');
        }
        $hash = hash('sha256', json_encode($command, JSON_THROW_ON_ERROR));
        return $this->store->atomic(function () use ($command, $actor, $requestId, $revision, $action, $hash): array {
            if ($this->access->requireActor()->id !== $actor->id) {
                throw new AssetError('denied');
            }
            $receipt = $this->store->receipt($requestId);
            if ($receipt !== null) {
                if ($receipt['actor_id'] !== $actor->id || $receipt['command_hash'] !== $hash) {
                    throw new AssetError('request_reused');
                }
                return $receipt['result'];
            }
            $before = $this->store->snapshot();
            if ($revision !== $before['revision']) {
                throw new AssetError('stale_revision');
            }
            if ($action !== 'configure' && $before['policy'] === []) {
                throw new AssetError('configure_first');
            }
            $after = $before;
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $result = ['request_id' => $requestId, 'revision' => $revision + 1];
            $observations = [];
            switch ($action) {
                case 'configure':
                    $freshness = AssetFields::integer($command['freshness_seconds'] ?? null, 2592000);
                    if ($freshness < 300) {
                        throw new AssetError('invalid_fields');
                    }
                    $after['policy'] = array_replace($after['policy'], [
                        'scope' => AssetFields::text($command['scope'] ?? null, 200, true),
                        'steward' => AssetFields::text($command['steward'] ?? null, 200, true),
                        'freshness_seconds' => $freshness,
                    ]);
                    break;
                case 'create':
                    if (count($after['assets']) >= self::MAX_ASSETS) {
                        throw new AssetError('capacity');
                    }
                    $id = bin2hex(random_bytes(16));
                    $after['assets'][$id] = ['id' => $id, 'class' => 'network_equipment', 'state' => 'planned', 'fields' => AssetFields::accepted($this->object($command['fields'] ?? null)), 'created_at' => $now];
                    $result['asset_id'] = $id;
                    break;
                case 'edit':
                    $id = $this->asset($after, $command['asset_id'] ?? null);
                    $this->evidence($command);
                    if ($after['assets'][$id]['state'] === 'disposed') {
                        throw new AssetError('invalid_transition');
                    }
                    $after['assets'][$id]['fields'] = AssetFields::accepted(array_replace($after['assets'][$id]['fields'], $this->object($command['fields'] ?? null)));
                    Lifecycle::requireOwnership($after['assets'][$id]);
                    break;
                case 'transition':
                    $id = $this->asset($after, $command['asset_id'] ?? null);
                    $this->evidence($command);
                    $state = AssetFields::text($command['state'] ?? null, 20, true);
                    $after['assets'][$id] = Lifecycle::transition($after, $id, $state);
                    break;
                case 'link':
                    $key = $this->identity($after, $command['identity_id'] ?? null);
                    $id = ($command['asset_id'] ?? null) === '' ? '' : $this->asset($after, $command['asset_id'] ?? null);
                    $this->evidence($command);
                    if ($id !== '' && $after['assets'][$id]['state'] === 'disposed') {
                        throw new AssetError('invalid_transition');
                    }
                    $identity = $after['identities'][$key] ?? throw new AssetError('not_found');
                    $identity['asset_id'] = $id;
                    $after['identities'][$key] = $identity;
                    break;
                case 'accept':
                    $key = $this->identity($after, $command['identity_id'] ?? null);
                    $identity = $after['identities'][$key];
                    $id = $this->asset($after, $identity['asset_id']);
                    $this->evidence($command);
                    $fields = $command['fields'] ?? null;
                    if (!is_array($fields) || !array_is_list($fields) || $fields === [] || count($fields) > 4 || $after['assets'][$id]['state'] === 'disposed') {
                        throw new AssetError('invalid_fields');
                    }
                    foreach ($fields as $field) {
                        if (!is_string($field) || !in_array($field, ['name', 'manufacturer', 'serial', 'location'], true) || !isset($identity['fields'][$field])) {
                            throw new AssetError('invalid_fields');
                        }
                        $after['assets'][$id]['fields'][$field] = $identity['fields'][$field];
                    }
                    $after['assets'][$id]['fields'] = AssetFields::accepted($after['assets'][$id]['fields']);
                    break;
                case 'dependency':
                    $this->dependency($after, $command);
                    break;
                case 'import':
                    if (($command['source'] ?? null) === 'kadupul-monitoring') {
                        throw new AssetError('invalid_observation');
                    }
                    $observations = $this->import($after, $command, $requestId, $now);
                    $result['observations'] = count($observations);
                    break;
                case 'monitoring':
                    $ids = $command['device_ids'] ?? null;
                    if (!is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > self::MAX_BATCH) {
                        throw new AssetError('invalid_fields');
                    }
                    $ids = array_map(static fn(mixed $id): int => AssetFields::integer($id, 2147483647), $ids);
                    foreach ($ids as $id) {
                        if ($id < 1) {
                            throw new AssetError('invalid_fields');
                        }
                    }
                    if (count(array_unique($ids)) !== count($ids)) {
                        throw new AssetError('invalid_fields');
                    }
                    $observations = $this->import($after, ['source' => 'kadupul-monitoring', 'observations' => $this->monitoring->observations($ids, $now)], $requestId, $now);
                    $result['observations'] = count($observations);
                    break;
                case 'source_failure':
                    $source = AssetFields::source($command['source'] ?? null);
                    $after['sources'][$source] = array_replace($after['sources'][$source] ?? [], ['status' => 'failed', 'attempted_at' => $now]);
                    break;
                case 'baseline':
                    $ids = $command['asset_ids'] ?? null;
                    if (!is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > self::MAX_ASSETS) {
                        throw new AssetError('invalid_fields');
                    }
                    $ids = array_map(AssetFields::id(...), $ids);
                    if (count(array_unique($ids)) !== count($ids)) {
                        throw new AssetError('invalid_fields');
                    }
                    $after['policy']['baseline'] = ['asset_ids' => $ids, 'evidence' => $this->evidence($command), 'verified_at' => $now];
                    break;
                case 'review':
                    $kind = AssetFields::text($command['kind'] ?? null, 30, true);
                    if (!in_array($kind, ['discrepancies', 'lifecycle', 'identity_sample'], true)) {
                        throw new AssetError('invalid_fields');
                    }
                    $review = ['evidence' => $this->evidence($command), 'reviewed_at' => $now, 'actor_id' => $actor->id];
                    if ($kind === 'identity_sample') {
                        $sample = AssetFields::integer($command['sample_size'] ?? null, self::MAX_IDENTITIES);
                        if ($sample < 1) {
                            throw new AssetError('invalid_fields');
                        }
                        $review += ['sample_size' => $sample, 'incorrect_merges' => AssetFields::integer($command['incorrect_merges'] ?? null, $sample), 'missed_duplicates' => AssetFields::integer($command['missed_duplicates'] ?? null, $sample)];
                    }
                    $after['policy']['reviews'][$kind] = $review;
                    break;
            }
            if (count($after['sources']) > 50) {
                throw new AssetError('capacity');
            }
            $after['revision'] = $revision + 1;
            $changes = [];
            foreach (['policy', 'assets', 'identities', 'dependencies', 'sources'] as $collection) {
                if ($before[$collection] !== $after[$collection]) {
                    $changes[$collection] = ['before' => array_diff_assoc(array_map(static fn($v) => json_encode($v, JSON_THROW_ON_ERROR), $before[$collection]), array_map(static fn($v) => json_encode($v, JSON_THROW_ON_ERROR), $after[$collection])), 'after' => array_diff_assoc(array_map(static fn($v) => json_encode($v, JSON_THROW_ON_ERROR), $after[$collection]), array_map(static fn($v) => json_encode($v, JSON_THROW_ON_ERROR), $before[$collection]))];
                }
            }
            $this->store->save($before, $after);
            $this->store->observe($observations);
            $this->store->journal(['request_id' => $requestId, 'actor_id' => $actor->id, 'actor' => $actor->username, 'action' => $action, 'occurred_at' => $now, 'command_hash' => $hash, 'evidence' => isset($command['evidence']) ? $this->evidence($command) : '', 'changes' => $changes, 'result' => $result]);
            return $result;
        });
    }

    /**
     * @param Snapshot $state
     * @param array<string, mixed> $command
     * @return list<array<string, mixed>>
     */
    private function import(array &$state, array $command, string $run, string $now): array
    {
        $source = AssetFields::source($command['source'] ?? null);
        $rows = $command['observations'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::MAX_BATCH) {
            throw new AssetError('invalid_observation');
        }
        $observations = [];
        $seen = [];
        foreach ($rows as $row) {
            $row = $this->object($row);
            if (array_diff(array_keys($row), ['key', 'observed_at', 'fields']) !== []) {
                throw new AssetError('invalid_observation');
            }
            $external = AssetFields::externalKey($row['key'] ?? null);
            $id = hash('sha256', $source . "\0" . $external);
            $time = AssetFields::text($row['observed_at'] ?? null, 20, true);
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $time, new \DateTimeZone('UTC'));
            if ($date === false || $date->format('Y-m-d\TH:i:s\Z') !== $time || $time > $now || $time < '1970-01-01T00:00:00Z' || isset($seen[$id])) {
                throw new AssetError('invalid_observation');
            }
            $seen[$id] = true;
            $fields = AssetFields::observed($this->object($row['fields'] ?? null));
            $old = $state['identities'][$id] ?? null;
            if ($old !== null && ($old['observed_at'] > $time || $old['observed_at'] === $time && $old['fields'] !== $fields)) {
                throw new AssetError('older_observation');
            }
            $state['identities'][$id] = ['id' => $id, 'source' => $source, 'key' => $external, 'asset_id' => $old['asset_id'] ?? '', 'observed_at' => $time, 'ingested_at' => $now, 'first_seen_at' => $old['first_seen_at'] ?? $now, 'fields' => $fields];
            if ($old === null || $old['observed_at'] !== $time || $old['fields'] !== $fields) {
                $observations[] = ['run_id' => $run, ...$state['identities'][$id]];
            }
        }
        if (count($state['identities']) > self::MAX_IDENTITIES) {
            throw new AssetError('capacity');
        }
        $state['sources'][$source] = ['status' => 'ok', 'attempted_at' => $now, 'succeeded_at' => $now, 'rows' => count($rows)];
        return $observations;
    }

    /**
     * @param Snapshot $state
     * @param array<string, mixed> $command
     */
    private function dependency(array &$state, array $command): void
    {
        $from = $this->asset($state, $command['asset_id'] ?? null);
        $to = $this->asset($state, $command['depends_on'] ?? null);
        $this->evidence($command);
        if (!is_bool($command['remove'] ?? null) || $from === $to) {
            throw new AssetError('invalid_fields');
        }
        $key = $from . ':' . $to;
        if ($command['remove']) {
            unset($state['dependencies'][$key]);
            return;
        }
        if (count($state['dependencies']) >= 2000 || in_array($state['assets'][$to]['state'], ['retired', 'disposed'], true)) {
            throw new AssetError('invalid_dependency');
        }
        $state['dependencies'][$key] = ['asset_id' => $from, 'depends_on' => $to];
        $queue = [$to];
        $seen = [];
        while ($queue !== []) {
            $node = array_pop($queue);
            if ($node === $from) {
                throw new AssetError('dependency_cycle');
            }
            if (isset($seen[$node])) {
                continue;
            }
            $seen[$node] = true;
            foreach ($state['dependencies'] as $edge) {
                if ($edge['asset_id'] === $node) {
                    $queue[] = $edge['depends_on'];
                }
            }
        }
    }

    /**
     * @param Snapshot $state
     */
    private function asset(array $state, mixed $id): string
    {
        $id = AssetFields::id($id);
        if (!isset($state['assets'][$id])) {
            throw new AssetError('not_found');
        }
        return $id;
    }

    /**
     * @param Snapshot $state
     */
    private function identity(array $state, mixed $id): string
    {
        if (!is_string($id) || !isset($state['identities'][$id])) {
            throw new AssetError('not_found');
        }
        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function object(mixed $value): array
    {
        return AssetData::object($value);
    }

    /**
     * @param array<string, mixed> $command
     */
    private function evidence(array $command): string
    {
        return AssetFields::text($command['evidence'] ?? null, 500, true);
    }
}
