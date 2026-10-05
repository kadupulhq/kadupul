<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once __DIR__ . '/../src/Platform/Infrastructure/Legacy/LegacyReferenceWriteTransaction.php';

final class GraphDataRemovalAccessDenied extends RuntimeException {}
final class GraphDataRemovalBatchTooLarge extends RuntimeException {}

/** Web removal scope; trusted CLI/device APIs retain their established contracts. */
final class GraphDataRemovalScope
{
    private const MAX_SCOPE = 10000;
    private static ?self $active = null;
    private array $graphs = array();
    private array $data = array();
    private array $owners = array();
    private array $relations = array();
    private array $remoteConnections = array();
    private array $attemptedCollectors = array();
    private array $volatileDeletes = array();
    private bool $volatileAttempted = false;
    private ?PDO $database = null;
    private bool $locking = false;

    private function __construct(private readonly string $resource, private readonly array $selected, private readonly int $mode) {}

    /** @return list<int> */
    public static function ids(array $values): array
    {
        if (!$values || count($values) > self::MAX_SCOPE) {
            throw new RuntimeException('Invalid removal selection.');
        }
        $ids = array();
        foreach ($values as $value) {
            $id = auth_resource_id($value);
            if ($id === null || $id === 0) {
                throw new RuntimeException('Invalid removal identity.');
            }
            $ids[$id] = $id;
        }
        sort($ids, SORT_NUMERIC);
        return array_values($ids);
    }

    public static function review(string $resource, array $ids, mixed $mode): self
    {
        $mode = auth_resource_id($mode);
        if (!in_array($resource, array('graph', 'data'), true) || !in_array($mode, $resource === 'graph' ? array(1, 2) : array(1, 2, 3), true)) {
            throw new RuntimeException('Invalid removal mode.');
        }
        $scope = new self($resource, self::ids($ids), $mode);
        $scope->capture(false);
        return $scope;
    }

    public function dataIds(): array
    {
        return $this->data;
    }

    public function selectedIds(): array
    {
        return $this->selected;
    }

    public function graphIds(): array
    {
        return $this->graphs;
    }

    public function graphsFromSources(): array
    {
        $ids = array();
        $selected = array_fill_keys($this->selected, true);
        foreach ($this->relations as $key => $row) {
            if (str_starts_with($key, 'item:') && isset($selected[(int) $row['local_data_id']])) $ids[(int) $row['local_graph_id']] = (int) $row['local_graph_id'];
        }
        return array_values($ids);
    }

    public function deleteGraphItems(): void
    {
        $items = array_column(self::dependentRows('SELECT id FROM data_template_rrd WHERE local_data_id IN (' . implode(',', $this->selected) . ')', false), 'id', 'id');
        $pollers = $this->pollers($this->selected);
        api_plugin_hook_function('graph_items_remove', $items);
        $this->verify();
        foreach (array_chunk($items, 1000) as $chunk) {
            $sql = 'DELETE FROM graph_templates_item WHERE task_item_id IN (' . implode(',', $chunk) . ') AND local_graph_id > 0';
            self::execute($sql);
            foreach ($pollers as $poller) self::execute($sql, true, self::remote($poller, true));
        }
    }

    /** Authorize identifiers/owners before dependent names, hooks or mutation. */
    private function authorize(string $resource, array $ids, bool $allow_removed): array
    {
        if (!$ids) return array();
        $actor = auth_resource_id($_SESSION['sess_user_id'] ?? 0);
        if (read_config_option('auth_method') != 0 && ($actor === null || $actor <= 0 || !is_realm_allowed($resource === 'graph' ? 5 : 3, $actor))) {
            throw new GraphDataRemovalAccessDenied('Removal access denied.');
        }
        if (read_config_option('auth_method') != 0) {
            $account = self::rows('SELECT enabled, locked FROM user_auth WHERE id = ' . $actor);
            if (count($account) !== 1 || $account[0]['enabled'] !== 'on' || $account[0]['locked'] === 'on') throw new GraphDataRemovalAccessDenied('Removal access denied.');
        }
        // A plugin can invalidate policy during this same request, after bootstrap.
        if (isset($_SESSION['sess_simple_perms']) && is_array($_SESSION['sess_simple_perms'])) {
            unset($_SESSION['sess_simple_perms'][$actor]);
        }
        $devices = self::checked(static fn() => get_allowed_management_device_ids_sql());
        $graphs = $resource === 'graph' ? self::checked(static fn() => get_allowed_management_graph_ids_sql()) : '';
        $table = $resource === 'graph' ? 'graph_local' : 'data_local';
        $owners = array();
        foreach (array_chunk($ids, 1000) as $chunk) {
            $list = implode(',', $chunk);
            $rows = self::rows("SELECT id, host_id FROM $table WHERE id IN ($list)" . ($this->locking ? ' FOR UPDATE' : ''));
            $allowed = self::rows("SELECT id FROM $table WHERE id IN ($list)
                AND (host_id = 0 OR (host_id > 0 AND host_id IN ($devices)))" . ($resource === 'graph' ? " AND id IN ($graphs)" : ''));
            if (count($rows) !== count($allowed) || (!$allow_removed && count($rows) !== count($chunk))) {
                throw new GraphDataRemovalAccessDenied('Removal access denied.');
            }
            foreach ($rows as $row) $owners[$resource . ':' . $row['id']] = (int) $row['host_id'];
        }
        return $owners;
    }

    /** Identity-only dependency reads; no graph/source titles are selected. */
    private function discover(bool $lock): array
    {
        $graphs = $this->resource === 'graph' ? $this->selected : array();
        $data = $this->resource === 'data' ? $this->selected : array();
        $relations = array();
        if (($this->resource === 'graph' && $this->mode === 2) || ($this->resource === 'data' && $this->mode > 1)) {
            foreach (array_chunk($this->selected, 1000) as $chunk) {
                $where = $this->resource === 'graph' ? 'gti.local_graph_id' : 'dtr.local_data_id';
                $rows = self::dependentRows('SELECT gti.id, gti.local_graph_id, gti.task_item_id, dtr.local_data_id
                    FROM graph_templates_item AS gti INNER JOIN data_template_rrd AS dtr ON dtr.id=gti.task_item_id
                    WHERE ' . $where . ' IN (' . implode(',', $chunk) . ') AND gti.local_graph_id > 0 AND dtr.local_data_id > 0', $lock);
                foreach ($rows as $row) {
                    $relations['item:' . $row['id']] = array_map('intval', $row);
                    self::boundRelations($relations);
                    if ($this->resource === 'graph') $data[] = (int) $row['local_data_id'];
                    else $graphs[] = (int) $row['local_graph_id'];
                }
                $graphs = self::boundedIds($graphs);
                $data = self::boundedIds($data);
            }
        }
        if ($this->resource === 'graph' && $this->mode === 2) {
            foreach (array_chunk(array_values(array_unique($data)), 1000) as $chunk) {
                $rows = self::dependentRows('SELECT gti.id, gti.local_graph_id, gti.task_item_id, dtr.local_data_id
                    FROM graph_templates_item AS gti INNER JOIN data_template_rrd AS dtr ON dtr.id=gti.task_item_id
                    WHERE dtr.local_data_id IN (' . implode(',', $chunk) . ') AND gti.local_graph_id > 0', $lock);
                foreach ($rows as $row) {
                    $relations['item:' . $row['id']] = array_map('intval', $row);
                    self::boundRelations($relations);
                    $graphs[] = (int) $row['local_graph_id'];
                }
                $graphs = self::boundedIds($graphs);
            }
        }
        $graphs = array_values(array_unique($graphs));
        $aggregate_ids = array();
        $aggregate_children = array();
        // Graph removal regenerates aggregate parents, including graph-only mode.
        if ($this->resource === 'graph' || $this->mode === 3) {
            foreach (array_chunk($graphs, 1000) as $chunk) {
                $rows = self::dependentRows('SELECT agi.aggregate_graph_id, agi.local_graph_id, ag.local_graph_id AS parent_id
                    FROM aggregate_graphs_items AS agi INNER JOIN aggregate_graphs AS ag ON ag.id=agi.aggregate_graph_id
                    WHERE agi.local_graph_id IN (' . implode(',', $chunk) . ')', $lock);
                foreach ($rows as $row) {
                    $relations['aggregate:' . $row['aggregate_graph_id'] . ':' . $row['local_graph_id']] = array_map('intval', $row);
                    self::boundRelations($relations);
                    $graphs[] = (int) $row['parent_id'];
                    $aggregate_ids[] = (int) $row['aggregate_graph_id'];
                }
                $graphs = self::boundedIds($graphs);
                $aggregate_ids = self::boundedIds($aggregate_ids);
            }
        }
        // Regeneration reads the other members and their stored data sources too.
        foreach (array_chunk(array_values(array_unique($aggregate_ids)), 1000) as $chunk) {
            $rows = self::dependentRows('SELECT agi.aggregate_graph_id, agi.local_graph_id, ag.local_graph_id AS parent_id
                FROM aggregate_graphs_items AS agi INNER JOIN aggregate_graphs AS ag ON ag.id=agi.aggregate_graph_id
                WHERE agi.aggregate_graph_id IN (' . implode(',', $chunk) . ')', $lock);
            foreach ($rows as $row) {
                $relations['aggregate:' . $row['aggregate_graph_id'] . ':' . $row['local_graph_id']] = array_map('intval', $row);
                self::boundRelations($relations);
                $aggregate_children[] = (int) $row['local_graph_id'];
                $graphs[] = (int) $row['local_graph_id'];
            }
            $graphs = self::boundedIds($graphs);
            $aggregate_children = self::boundedIds($aggregate_children);
        }
        foreach (array_chunk(array_values(array_unique($aggregate_children)), 1000) as $chunk) {
            $rows = self::dependentRows('SELECT gti.id, gti.local_graph_id, gti.task_item_id, dtr.local_data_id
                FROM graph_templates_item AS gti INNER JOIN data_template_rrd AS dtr ON dtr.id=gti.task_item_id
                WHERE gti.local_graph_id IN (' . implode(',', $chunk) . ') AND dtr.local_data_id > 0', $lock);
            foreach ($rows as $row) {
                $relations['item:' . $row['id']] = array_map('intval', $row);
                self::boundRelations($relations);
                $data[] = (int) $row['local_data_id'];
            }
            $data = self::boundedIds($data);
        }
        return array(array_values(array_unique($graphs)), array_values(array_unique($data)), $relations);
    }

    private static function dependentRows(string $sql, bool $lock): array
    {
        $rows = self::rows($sql . ' LIMIT ' . (self::MAX_SCOPE + 1) . ($lock ? ' FOR UPDATE' : ''));
        if (count($rows) > self::MAX_SCOPE) throw new GraphDataRemovalBatchTooLarge('Removal dependency scope exceeds the supported batch.');
        return $rows;
    }

    private static function boundedIds(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if (count($ids) > self::MAX_SCOPE) throw new GraphDataRemovalBatchTooLarge('Removal dependency scope exceeds the supported batch.');
        return $ids;
    }

    private static function boundRelations(array $relations): void
    {
        if (count($relations) > self::MAX_SCOPE) throw new GraphDataRemovalBatchTooLarge('Removal dependency scope exceeds the supported batch.');
    }

    private function capture(bool $lock): void
    {
        $this->locking = $lock;
        $owners = $this->authorize($this->resource, $this->selected, false);
        [$graphs, $data, $relations] = $this->discover($lock);
        $owners += $this->authorize('graph', $graphs, false);
        $owners += $this->authorize('data', $data, false);
        $this->graphs = $graphs;
        $this->data = $data;
        $this->owners = $owners;
        $this->relations = $relations;
    }

    public function verify(): void
    {
        $this->assertConnection();
        $owners = $this->authorize('graph', $this->graphs, true) + $this->authorize('data', $this->data, true);
        foreach ($owners as $id => $host) {
            if (!array_key_exists($id, $this->owners) || $this->owners[$id] !== $host) {
                throw new RuntimeException('Removal ownership changed.');
            }
        }
        [$graphs, $data, $relations] = $this->discover(true);
        if (array_diff($graphs, $this->graphs) || array_diff($data, $this->data)) {
            throw new RuntimeException('Removal dependency scope changed.');
        }
        foreach ($relations as $id => $row) {
            if (!isset($this->relations[$id]) || $this->relations[$id] !== $row) {
                throw new RuntimeException('Removal dependency scope changed.');
            }
        }
    }

    public function removeAggregateItems(array $ids): void
    {
        $parents = array();
        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = self::rows('SELECT ag.local_graph_id AS parent_id, agi.local_graph_id AS child_id
                FROM aggregate_graphs_items AS agi INNER JOIN aggregate_graphs AS ag ON ag.id=agi.aggregate_graph_id
                WHERE agi.local_graph_id IN (' . implode(',', $chunk) . ')');
            foreach ($rows as $row) $parents[(int) $row['parent_id']][] = (int) $row['child_id'];
        }
        if (!$parents) return;
        require_once __DIR__ . '/api_aggregate.php';
        if (!aggregate_graph_mutation(static function () use ($parents): bool {
            foreach ($parents as $parent => $children) {
                if (api_aggregate_disassociate($parent, $children) !== true) return false;
            }
            return true;
        })) throw new RuntimeException('Aggregate graph regeneration failed before graph removal.');
    }

    public function run(Closure $operation): void
    {
        global $database_sessions, $database_hostname, $database_port, $database_default;
        $database = $database_sessions["$database_hostname:$database_port:$database_default"] ?? null;
        if (!$database instanceof PDO) throw new RuntimeException('Removal connection unavailable.');
        $this->database = $database;
        $this->assertConnection();
        $previous = self::$active;
        self::$active = $this;
        try {
            (new \Kadupul\Platform\Infrastructure\Legacy\LegacyReferenceWriteTransaction($database))->run(function () use ($operation) {
                $this->locking = true;
                $this->authorize($this->resource, $this->selected, false);
                $this->verify();
                self::checked($operation);
                $this->verify();
                // Hourly caches use MEMORY in the shipped schema. Invalidation
                // is deliberately deferred, but is not undone by local rollback.
                foreach ($this->volatileDeletes as $sql) {
                    $this->volatileAttempted = true;
                    self::execute($sql);
                }
                return true;
            }, $this->participants());
        } catch (Throwable $error) {
            if ($this->attemptedCollectors || $this->volatileAttempted) {
                $details = $this->attemptedCollectors ? ' Collector changes may be partial; reconcile collectors before retrying.' : '';
                $details .= $this->volatileAttempted ? ' Volatile statistics caches may have been invalidated; persistent transaction cleanup was attempted separately.' : '';
                throw new RuntimeException('Removal failed.' . $details, 0, $error);
            }
            throw $error;
        } finally {
            self::$active = $previous;
            $this->database = null;
            $this->locking = false;
        }
    }

    private function participants(): array
    {
        $tables = array('settings');
        if ($this->resource === 'graph' || $this->mode > 1) $tables[] = 'graph_templates_item';
        if ($this->resource === 'graph' || $this->mode === 3) {
            $tables = array_merge($tables, array('graph_local', 'graph_templates_graph', 'graph_tree_items', 'reports_items',
                'aggregate_graphs', 'aggregate_graphs_items', 'aggregate_graphs_graph_item', 'cdef', 'cdef_items'));
        }
        if ($this->resource === 'data' || $this->mode === 2) {
            $tables = array_merge($tables, array('data_local', 'data_template_data', 'data_template_rrd', 'data_input_data',
                'poller_item', 'data_debug', 'data_source_stats_daily', 'data_source_stats_hourly', 'data_source_stats_monthly',
                'data_source_stats_weekly', 'data_source_stats_yearly', 'poller_output', 'poller_output_boost', 'data_source_purge_action'));
        }
        return $tables;
    }

    public function queueVolatileDelete(string $table, string $ids): void
    {
        if (!in_array($table, array('data_source_stats_hourly_cache', 'data_source_stats_hourly_last'), true)) {
            throw new RuntimeException('Invalid volatile cache participant.');
        }
        $selected = self::ids(array_map('trim', explode(',', $ids)));
        if (array_diff($selected, $this->data)) throw new RuntimeException('Volatile cache removal scope changed.');
        $create = $this->database->query('SHOW CREATE TABLE `' . $table . '`');
        $row = $create === false ? false : $create->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_string($row['Create Table'] ?? null) || preg_match('/\ACREATE TABLE\s/i', $row['Create Table']) !== 1
            || $create->errorCode() !== '00000' || !$create->closeCursor()) throw new RuntimeException('Volatile cache metadata unavailable.');
        $status = $this->database->query("SHOW TABLE STATUS WHERE Name = '$table'");
        $row = $status === false ? false : $status->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || ($row['Name'] ?? null) !== $table || !in_array($row['Engine'] ?? null, array('MEMORY', 'InnoDB'), true)
            || $status->fetch(PDO::FETCH_ASSOC) !== false || $status->errorCode() !== '00000' || !$status->closeCursor()) {
            throw new RuntimeException('Unsupported volatile cache storage.');
        }
        foreach (array_chunk($selected, 1000) as $chunk) $this->volatileDeletes[] = 'DELETE FROM ' . $table . ' WHERE local_data_id IN (' . implode(',', $chunk) . ')';
    }

    private function assertConnection(): void
    {
        if ($this->database === null) return;
        global $database_sessions, $database_hostname, $database_port, $database_default;
        if (($database_sessions["$database_hostname:$database_port:$database_default"] ?? null) !== $this->database) throw new RuntimeException('Removal connection changed.');
        $statement = $this->database->query('SELECT DATABASE()');
        if ($statement === false) throw new RuntimeException('Removal schema unavailable.');
        $schema = $statement->fetchColumn();
        if (!$statement->closeCursor() || $schema !== $database_default) throw new RuntimeException('Removal schema changed.');
    }

    public static function checked(Closure $operation): mixed
    {
        global $database_last_error;
        $previous = $database_last_error ?? null;
        $database_last_error = null;
        try {
            $result = $operation();
            if ($result === false || !empty($database_last_error)) throw new RuntimeException('Removal database operation failed.');
            return $result;
        } finally {
            $database_last_error = $previous;
        }
    }

    private static function rows(string $sql): array
    {
        return self::checked(static fn() => db_fetch_assoc($sql));
    }

    public static function fetch(string $sql): array
    {
        return self::rows($sql);
    }

    public function pollers(array $ids): array
    {
        return array_column(self::rows('SELECT DISTINCT h.poller_id FROM host AS h
            INNER JOIN data_local AS dl ON h.id=dl.host_id WHERE h.poller_id > 1
            AND dl.id IN (' . implode(',', $ids) . ')'), 'poller_id');
    }

    public static function execute(string $sql, bool $log = true, mixed $connection = false): mixed
    {
        if (self::$active === null) return db_execute($sql, $log, $connection);
        self::$active->assertConnection();
        if ($connection !== false) self::$active->attemptedCollectors[spl_object_id($connection)] = true;
        return self::checked(static fn() => db_execute($sql, $log, $connection));
    }

    public static function remote(mixed $poller_id, bool $is_poller = true): mixed
    {
        if (self::$active === null) return poller_push_to_remote_db_connect($poller_id, $is_poller);
        $poller_id = (int) $poller_id;
        if ($poller_id <= 1) return false;
        if (!isset(self::$active->remoteConnections[$poller_id])) {
            $connection = poller_push_to_remote_db_connect($poller_id, $is_poller);
            if ($connection === false) throw new RuntimeException('Removal collector connection failed.');
            self::$active->remoteConnections[$poller_id] = $connection;
        }
        return self::$active->remoteConnections[$poller_id];
    }
}

/** Stop failed confirmation/execution before bottom hooks can publish an outcome. */
function graph_data_removal_failed(string $resource, Throwable $error): never
{
    cacti_log('ERROR: Graph/data removal failed: ' . $error->getMessage(), false, 'AUTH');
    $partial = str_contains($error->getMessage(), 'Collector changes may be partial');
    $volatile = str_contains($error->getMessage(), 'Volatile statistics caches');
    raise_message('removal_failed', $error instanceof GraphDataRemovalBatchTooLarge
        ? __('The removal dependency scope exceeds the supported batch. Select fewer records and try again.')
        : ($volatile
        ? __('The removal failed. Persistent transaction cleanup was attempted separately; volatile statistics caches may have been invalidated. Reload and reconcile any collector changes before retrying.')
        : ($partial
        ? __('The removal failed and collector changes may be partial. Reconcile collectors before retrying.')
        : __('The removal could not be completed. Reload and review the selection before retrying.'))), MESSAGE_LEVEL_ERROR);
    header('Location: ' . ($resource === 'graph' ? 'graphs.php' : 'data_sources.php') . '?header=false');
    exit;
}
