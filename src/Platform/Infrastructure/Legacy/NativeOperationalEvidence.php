<?php

declare(strict_types=1);

/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\Platform\Application\Installer\OperationalEvidence;
use Kadupul\Platform\Application\Port\Clock;
use Kadupul\Platform\Domain\Installer\OperationalVerificationRequest;

final class NativeOperationalEvidence implements OperationalEvidence
{
    public function __construct(private readonly Clock $clock, private readonly LocalRrdVerification $rrd) {}

    /** @param list<int> $graphIds
     *  @return array<string, mixed>
     */
    public function inspect(int $actorId, array $graphIds): array
    {
        $request = OperationalVerificationRequest::fromGraphIds($graphIds);
        $database = LegacyConnectionRegistry::select(null);
        $local = $GLOBALS['local_db_cnn_id'] ?? null;
        if (!$database instanceof \PDO || ($local instanceof \PDO && $local !== $database)) {
            throw new \RuntimeException('A confirmed local database connection is required.');
        }
        if ($actorId < 1) {
            throw new \RuntimeException('An attributed operator is required.');
        }
        foreach ($request->graphIds as $id) {
            if (!function_exists('is_graph_allowed') || \is_graph_allowed($id, $actorId) !== true) {
                throw new \RuntimeException('A requested graph is unavailable to this operator.');
            }
        }
        $configuration = $GLOBALS['config'] ?? null;
        if (!is_array($configuration)) {
            throw new \RuntimeException('Native operational configuration is unavailable.');
        }
        $poller = self::integer($configuration['poller_id'] ?? null, 65535);
        if ($poller === null || $poller === 0) {
            throw new \RuntimeException('The local poller identity is unavailable.');
        }
        $now = $this->clock->now()->getTimestamp();
        $deadline = microtime(true) + 8.0;
        $names = ['cron_interval', 'poller_interval', 'poller_lastrun_' . $poller, 'storage_location', 'path_rrdtool'];
        $settings = [];
        foreach ($this->rows($database, 'SELECT name, value FROM settings WHERE name IN (?, ?, ?, ?, ?) LIMIT 5', $names) as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException('Operational settings could not be confirmed.');
            }
            if (is_string($row['name'] ?? null) && is_string($row['value'] ?? null)) {
                $settings[$row['name']] = $row['value'];
            }
        }
        $scheduler = InstallerHealthAssessment::pollerActivity(
            $settings['poller_lastrun_' . $poller] ?? null,
            $settings['cron_interval'] ?? '300',
            $settings['poller_interval'] ?? '300',
            $this->clock->now()->getTimestamp()
        );
        $scheduler = ['status' => $scheduler['status'], 'reason' => match ($scheduler['status']) {
            'verified' => 'persisted_start_within_native_tolerance',
            'action_required' => 'persisted_start_stale_future_or_invalid',
            default => 'persisted_start_absent',
        }];
        $records = $this->rows($database, 'SELECT disabled, status, total_polls, UNIX_TIMESTAMP(last_update) AS completed FROM poller WHERE id = ? LIMIT 1', [$poller]);
        $record = $records[0] ?? null;
        if ($record !== null && !is_array($record)) {
            throw new \RuntimeException('Local poller evidence could not be confirmed.');
        }
        $collection = $this->collection($record === null ? null : ['disabled' => $record['disabled'] ?? null,
            'status' => $record['status'] ?? null, 'total_polls' => $record['total_polls'] ?? null, 'completed' => $record['completed'] ?? null], $settings, $this->clock->now()->getTimestamp());
        $sources = [];
        $readAt = [];
        $graphs = [];
        $proxy = ($configuration['force_storage_location_local'] ?? false) !== true && (bool) ($settings['storage_location'] ?? '');
        foreach ($request->graphIds as $id) {
            $graph = ['id' => $id, 'metadata' => 'observed', 'rendering' => 'not_assessed', 'sources' => []];
            if (microtime(true) >= $deadline) {
                $graph['metadata'] = 'not_assessed';
                $graph['reason'] = 'assessment_budget_exhausted';
                $graphs[] = $graph;
                continue;
            }
            $items = $this->rows($database, 'SELECT DISTINCT gti.task_item_id, dtr.local_data_id, dtr.rrd_heartbeat FROM graph_templates_item gti LEFT JOIN data_template_rrd dtr ON dtr.id = gti.task_item_id WHERE gti.local_graph_id = ? AND gti.task_item_id > 0 LIMIT 21', [$id]);
            if (count($items) > 20) {
                $graph['metadata'] = 'not_assessed';
                $graph['reason'] = 'graph_source_limit_exceeded';
                $graphs[] = $graph;
                continue;
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new \RuntimeException('Graph evidence could not be confirmed.');
                }
                $localId = self::integer($item['local_data_id'] ?? null);
                $heartbeat = self::integer($item['rrd_heartbeat'] ?? null);
                if ($localId === null || $localId === 0) {
                    $graph['sources'][] = ['status' => 'failed', 'reason' => 'graph_source_reference_missing'];
                    continue;
                }
                if (!isset($sources[$localId])) {
                    if (count($sources) >= 20 || microtime(true) >= $deadline) {
                        $graph['sources'][] = ['local_data_id' => $localId, 'status' => 'not_assessed', 'reason' => 'assessment_source_or_time_limit'];
                        continue;
                    }
                    $paths = $this->rows($database, 'SELECT data_source_path FROM data_template_data WHERE local_data_id = ? LIMIT 2', [$localId], \PDO::FETCH_COLUMN);
                    $path = count($paths) === 1 && is_string($paths[0]) ? $paths[0] : '';
                    $sources[$localId] = $proxy ? ['status' => 'not_assessed', 'reason' => 'remote_storage_not_contacted']
                        : ($paths === [] ? ['status' => 'absent', 'reason' => 'data_source_configuration_missing']
                            : (count($paths) > 1 ? ['status' => 'failed', 'reason' => 'data_source_configuration_ambiguous']
                                : $this->rrd->read(
                                    $settings['path_rrdtool'] ?? '',
                                    $path,
                                    is_string($configuration['rra_path'] ?? null) ? $configuration['rra_path'] : '',
                                    $deadline - microtime(true)
                                )));
                    $readAt[$localId] = $this->clock->now()->getTimestamp();
                }
                $evidence = $sources[$localId];
                if (isset($evidence['last_update'])) {
                    $last = $evidence['last_update'];
                    $observedAt = $readAt[$localId];
                    if ($last === 0) {
                        $evidence['status'] = 'absent';
                        $evidence['reason'] = 'local_rrd_has_no_timestamp';
                    } elseif ($last > $observedAt) {
                        $evidence['status'] = 'failed';
                        $evidence['reason'] = 'local_rrd_timestamp_in_future';
                    } elseif ($heartbeat === null || $heartbeat === 0) {
                        $evidence['status'] = 'not_assessed';
                        $evidence['reason'] = 'timestamp_read_but_heartbeat_unavailable';
                    } elseif ($observedAt - $last > $heartbeat) {
                        $evidence['status'] = 'stale';
                        $evidence['reason'] = 'local_rrd_older_than_configured_heartbeat';
                    }
                }
                $graph['sources'][] = ['local_data_id' => $localId] + $evidence;
            }
            if ($items === []) {
                $graph['metadata'] = 'not_assessed';
                $graph['reason'] = 'graph_has_no_persisted_data_sources';
            }
            $graphs[] = $graph;
        }
        return ['version' => 1, 'read_only' => true, 'operational_health_confirmed' => false,
            'sampled_at' => $now, 'poller_id' => $poller, 'scheduler' => $scheduler, 'collection' => $collection, 'graphs' => $graphs,
            'limitations' => ['no_devices_contacted', 'no_collection_started', 'graph_rendering_not_assessed', 'local_rrd_on_disk_only_no_daemon_flush', 'non_atomic_observation_interval_sampled_at_is_start']];
    }

    /** @param array<string, mixed>|null $record
     *  @param array<string, string> $settings
     *  @return array<string, mixed>
     */
    private function collection(?array $record, array $settings, int $now): array
    {
        if ($record === null) {
            return ['status' => 'absent', 'reason' => 'local_poller_record_missing'];
        }
        if (($record['disabled'] ?? '') === 'on') {
            return ['status' => 'not_assessed', 'reason' => 'local_poller_disabled'];
        }
        $status = self::integer($record['status'] ?? null);
        $last = self::integer($record['completed'] ?? null);
        $polls = self::integer($record['total_polls'] ?? null);
        if ($status === self::pollerStatus('POLLER_STATUS_DOWN') || $status === self::pollerStatus('POLLER_STATUS_HEARTBEAT')) {
            return ['status' => $status === self::pollerStatus('POLLER_STATUS_DOWN') ? 'failed' : 'stale', 'reason' => 'native_poller_status_requires_attention'];
        }
        if ($status !== self::pollerStatus('POLLER_STATUS_IDLE')) {
            return ['status' => 'not_assessed', 'reason' => 'native_poller_not_idle'];
        }
        if ($last === null || $last === 0 || $polls === null || $polls === 0) {
            return ['status' => 'absent', 'reason' => 'no_persisted_completed_cycle'];
        }
        $timing = InstallerHealthAssessment::pollerActivity((string) $last, $settings['cron_interval'] ?? '300', $settings['poller_interval'] ?? '300', $now);
        return ['status' => $last > $now ? 'failed' : ($timing['status'] === 'verified' ? 'observed' : ($timing['status'] === 'action_required' ? 'stale' : 'not_assessed')),
            'reason' => 'persisted_cycle_completion_only_not_device_success', 'last_completed_at' => $last, 'completed_cycles' => $polls];
    }

    /** @param list<mixed> $values
     *  @return list<mixed>
     */
    private function rows(\PDO $database, string $sql, array $values, int $mode = \PDO::FETCH_ASSOC): array
    {
        $query = $database->prepare($sql);
        if (!$query instanceof \PDOStatement || !$query->execute($values)) {
            throw new \RuntimeException('Operational evidence could not be read.');
        }
        $rows = $query->fetchAll($mode);
        if (!array_is_list($rows) || $query->errorCode() !== '00000' || !$query->closeCursor()) {
            throw new \RuntimeException('Operational evidence fetch could not be confirmed.');
        }
        return $rows;
    }

    private static function integer(mixed $value, int $maximum = PHP_INT_MAX): ?int
    {
        if ((!is_string($value) && !is_int($value)) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', (string) $value) !== 1) {
            return null;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $maximum]]);
        return is_int($integer) ? $integer : null;
    }

    private static function pollerStatus(string $name): int
    {
        $value = defined($name) ? constant($name) : null;
        if (!is_int($value)) {
            throw new \RuntimeException('Native poller status definitions are unavailable.');
        }
        return $value;
    }
}
