<?php

declare(strict_types=1);

/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\Platform\Contract\LegacyWorkerRunner;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * @phpstan-type EvidenceEntry array{status: string, reason: string, last_update?: int, last_completed_at?: int, completed_cycles?: int, local_data_id?: int}
 * @phpstan-type OperationalReport array{version: int, read_only: bool, operational_health_confirmed: bool, sampled_at: int, poller_id: int, scheduler: EvidenceEntry, collection: EvidenceEntry, graphs: list<array{id: int, metadata: string, rendering: string, sources: list<EvidenceEntry>, reason?: string}>, limitations: list<string>}
 */
final class LegacyOperationalVerificationClient
{
    public function __construct(#[Autowire(service: OperationalVerificationProcess::class)] private readonly LegacyWorkerRunner $worker) {}

    /** @param list<int> $graphIds
     *  @return OperationalReport
     */
    public function request(?string $username, array $graphIds): array
    {
        try {
            $result = $this->worker->run(
                'legacy-installer-verification.php',
                'KADUPUL_OPERATIONAL_RESULT',
                ['operator' => $username, 'graph_ids' => $graphIds, 'confirmed' => true],
                20.0
            );
        } catch (\Throwable) {
            throw new \RuntimeException('Operational evidence could not be confirmed. No active collection was requested.');
        }
        $data = $result['result']['data'] ?? null;
        if (!$result['ok'] || !is_array($data) || ($data['version'] ?? null) !== 1 || ($data['read_only'] ?? null) !== true
            || ($data['operational_health_confirmed'] ?? null) !== false || !is_array($data['graphs'] ?? null)
            || !is_array($data['scheduler'] ?? null) || !is_array($data['collection'] ?? null)) {
            throw new \RuntimeException('Operational evidence could not be confirmed. Check operator eligibility and the local runtime.');
        }
        if (!is_int($data['sampled_at'] ?? null) || $data['sampled_at'] < 1 || !is_int($data['poller_id'] ?? null)
            || $data['poller_id'] < 1 || $data['poller_id'] > 65535 || !array_is_list($data['graphs']) || count($data['graphs']) !== count($graphIds)) {
            throw $this->invalid();
        }
        $scheduler = $this->entry($data['scheduler'], [
            'persisted_start_within_native_tolerance' => ['verified'],
            'persisted_start_stale_future_or_invalid' => ['action_required'],
            'persisted_start_absent' => ['not_assessed'],
        ]);
        $collection = $this->entry($data['collection'], [
            'local_poller_record_missing' => ['absent'], 'local_poller_disabled' => ['not_assessed'],
            'native_poller_status_requires_attention' => ['failed', 'stale'], 'native_poller_not_idle' => ['not_assessed'],
            'no_persisted_completed_cycle' => ['absent'],
            'persisted_cycle_completion_only_not_device_success' => ['observed', 'stale', 'failed', 'not_assessed'],
        ], ['last_completed_at', 'completed_cycles']);
        if ($collection['reason'] === 'persisted_cycle_completion_only_not_device_success'
            && (!isset($collection['last_completed_at'], $collection['completed_cycles']) || $collection['last_completed_at'] < 1 || $collection['completed_cycles'] < 1)) {
            throw $this->invalid();
        }
        $graphs = [];
        foreach ($data['graphs'] as $index => $graph) {
            if (!is_array($graph) || ($graph['id'] ?? null) !== $graphIds[$index] || ($graph['rendering'] ?? null) !== 'not_assessed'
                || !in_array($graph['metadata'] ?? null, ['observed', 'not_assessed'], true) || !is_array($graph['sources'] ?? null)
                || !array_is_list($graph['sources']) || count($graph['sources']) > 20) {
                throw $this->invalid();
            }
            $safe = ['id' => $graphIds[$index], 'metadata' => $graph['metadata'], 'rendering' => 'not_assessed', 'sources' => []];
            if (($graph['metadata'] === 'observed' && $graph['sources'] === [])
                || ($graph['metadata'] === 'not_assessed' && (!isset($graph['reason']) || $graph['sources'] !== []))) {
                throw $this->invalid();
            }
            if (isset($graph['reason'])) {
                if (!in_array($graph['reason'], ['assessment_budget_exhausted', 'graph_source_limit_exceeded', 'graph_has_no_persisted_data_sources'], true)) {
                    throw $this->invalid();
                }
                $safe['reason'] = $graph['reason'];
            }
            foreach ($graph['sources'] as $source) {
                $entry = $this->entry($source, [
                    'path_not_persisted' => ['not_assessed'], 'local_path_rejected' => ['failed'], 'local_rrd_missing' => ['absent'],
                    'local_rrd_unreadable' => ['failed'], 'local_rrdtool_unavailable' => ['not_assessed'],
                    'local_read_budget_exhausted' => ['not_assessed'], 'local_rrdtool_failed_or_timed_out' => ['failed'],
                    'local_rrdtool_result_unconfirmed' => ['failed'], 'local_rrd_timestamp_read' => ['observed'],
                    'remote_storage_not_contacted' => ['not_assessed'], 'local_rrd_has_no_timestamp' => ['absent'],
                    'local_rrd_timestamp_in_future' => ['failed'], 'timestamp_read_but_heartbeat_unavailable' => ['not_assessed'],
                    'local_rrd_older_than_configured_heartbeat' => ['stale'], 'graph_source_reference_missing' => ['failed'],
                    'assessment_source_or_time_limit' => ['not_assessed'], 'data_source_configuration_missing' => ['absent'],
                    'data_source_configuration_ambiguous' => ['failed'],
                ], ['last_update']);
                if (in_array($entry['reason'], ['local_rrd_timestamp_read', 'local_rrd_has_no_timestamp', 'local_rrd_timestamp_in_future', 'timestamp_read_but_heartbeat_unavailable', 'local_rrd_older_than_configured_heartbeat'], true)
                    && (!isset($entry['last_update']) || ($entry['reason'] === 'local_rrd_has_no_timestamp' ? $entry['last_update'] !== 0 : $entry['last_update'] < 1))) {
                    throw $this->invalid();
                }
                if (!is_array($source) || (!array_key_exists('local_data_id', $source) && $entry['reason'] !== 'graph_source_reference_missing')) {
                    throw $this->invalid();
                }
                if (array_key_exists('local_data_id', $source)) {
                    if (!is_int($source['local_data_id']) || $source['local_data_id'] < 1 || $source['local_data_id'] > 4294967295) {
                        throw $this->invalid();
                    }
                    $entry['local_data_id'] = $source['local_data_id'];
                }
                $safe['sources'][] = $entry;
            }
            $graphs[] = $safe;
        }
        return ['version' => 1, 'read_only' => true, 'operational_health_confirmed' => false,
            'sampled_at' => $data['sampled_at'], 'poller_id' => $data['poller_id'], 'scheduler' => $scheduler, 'collection' => $collection, 'graphs' => $graphs,
            'limitations' => ['no_devices_contacted', 'no_collection_started', 'graph_rendering_not_assessed', 'local_rrd_on_disk_only_no_daemon_flush', 'non_atomic_observation_interval_sampled_at_is_start']];
    }

    /** @param array<string, list<string>> $reasons
     *  @param list<string> $fields
     *  @return EvidenceEntry
     */
    private function entry(mixed $value, array $reasons, array $fields = []): array
    {
        if (!is_array($value) || !is_string($value['reason'] ?? null) || !isset($reasons[$value['reason']])
            || !in_array($value['status'] ?? null, $reasons[$value['reason']], true)) {
            throw $this->invalid();
        }
        $safe = ['status' => $value['status'], 'reason' => $value['reason']];
        foreach (['last_update', 'last_completed_at', 'completed_cycles'] as $name) {
            if (array_key_exists($name, $value)) {
                if (!in_array($name, $fields, true) || !is_int($value[$name]) || $value[$name] < 0) {
                    throw $this->invalid();
                }
                $safe[$name] = $value[$name];
            }
        }
        return $safe;
    }

    private function invalid(): \RuntimeException
    {
        return new \RuntimeException('Operational evidence result is malformed or unconfirmed.');
    }
}
