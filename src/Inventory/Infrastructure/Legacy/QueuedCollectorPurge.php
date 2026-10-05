<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Closure;
use Kadupul\Platform\Contract\ReferenceWriteTransactionRunner;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/** Serialize queued cleanup with returning device assignments on both servers. */
final class QueuedCollectorPurge
{
    public function __construct(private readonly ReferenceWriteTransactionRunner $transactions) {}

    public static function primary(array $configuration, array $sessions, string $key, mixed $onlinePrimary): PDO
    {
        if (($configuration['poller_id'] ?? null) == 1) {
            $connection = $sessions[$key] ?? null;
        } elseif (($configuration['poller_id'] ?? 0) > 1 && ($configuration['connection'] ?? null) === 'online') {
            $connection = $onlinePrimary;
        } else {
            throw new RuntimeException('Authoritative collector connection unavailable');
        }
        if (!$connection instanceof PDO) {
            throw new RuntimeException('Authoritative collector connection unavailable');
        }
        return $connection;
    }

    public function run(PDO $primary, int $collector, array $command, Closure $connect, Closure $purge): string
    {
        if ($collector < 2 || $collector > 65535 || !is_string($command['command'] ?? null)
            || preg_match('/\A[1-9][0-9]*\z/D', $command['command']) !== 1
            || strlen($command['command']) > 8 || (int) $command['command'] > 16777215) {
            throw new RuntimeException('Invalid queued collector identity');
        }
        $this->command($command);
        if (!defined('POLLER_COMMAND_PURGE') || (string) $command['action'] !== (string) constant('POLLER_COMMAND_PURGE')) {
            throw new RuntimeException('Queued command is not a purge');
        }
        $id = (int) $command['command'];

        return $this->session($primary, function () use ($primary, $collector, $command, $id, $connect, $purge): string {
            return $this->transactions->run($primary, function () use ($primary, $collector, $command, $id, $connect, $purge): string {
                $host = $this->rows($primary, 'SELECT id, poller_id, deleted FROM host WHERE id = ? FOR UPDATE', [$id]);
                if (count($host) > 1) {
                    throw new RuntimeException('Primary device identity unavailable');
                }
                if ($host !== [] && ((string) $host[0]['id'] !== (string) $id || !is_string($host[0]['deleted'])
                    || preg_match('/\A[1-9][0-9]*\z/D', (string) $host[0]['poller_id']) !== 1 || (int) $host[0]['poller_id'] > 65535)) {
                    throw new RuntimeException('Primary collector identity unavailable');
                }
                $queued = $this->rows($primary, 'SELECT action, command, time, last_updated FROM poller_command WHERE poller_id = ? AND action = ? AND BINARY command = BINARY ? FOR UPDATE', [$collector, $command['action'], $command['command']]);
                if ($queued === [] || count($queued) === 1 && !$this->sameCommand($queued[0], $command)) {
                    return 'changed';
                }
                if (count($queued) !== 1) {
                    throw new RuntimeException('Queued collector identity unavailable');
                }
                if ($host !== [] && $host[0]['deleted'] === '' && (int) $host[0]['poller_id'] === $collector) {
                    $this->acknowledge($primary, $collector, $command);
                    return 'obsolete';
                }
                $remote = $connect();
                if (!$remote instanceof PDO || $remote === $primary) {
                    throw new RuntimeException('Collector connection unavailable');
                }
                $this->session($remote, function () use ($remote, $id, $purge): void {
                    $this->transactions->run($remote, function () use ($remote, $id, $purge): bool {
                        // The missing-row next-key lock also serializes a returning INSERT.
                        $this->rows($remote, 'SELECT id FROM host WHERE id = ? FOR UPDATE', [$id]);
                        if ($purge($remote, $id) !== true || !$remote->inTransaction()) {
                            throw new RuntimeException('Collector purge could not be confirmed');
                        }
                        return true;
                    }, ['host', 'host_graph', 'host_snmp_query', 'host_snmp_cache', 'poller_item', 'poller_reindex', 'graph_tree_items', 'reports_items', 'poller_command', 'data_local', 'graph_local', 'data_input_data', 'data_template_rrd', 'data_template_data', 'graph_templates_item']);
                });
                if (!$primary->inTransaction()) {
                    throw new RuntimeException('Primary cleanup ownership lost');
                }
                $this->acknowledge($primary, $collector, $command);
                return 'purged';
            }, ['host', 'poller_command']);
        });
    }

    /** Preserve non-PURGE behavior without deleting newer or failed commands. */
    public function acknowledge(PDO $connection, int $collector, array $command): void
    {
        $this->command($command);
        $query = $this->execute($connection, 'DELETE FROM poller_command WHERE poller_id = ? AND action = ? AND BINARY command = BINARY ? AND time = ? AND last_updated = ?', [$collector, $command['action'], $command['command'], $command['time'], $command['last_updated']]);
        if ($query->rowCount() !== 1) {
            throw new RuntimeException('Queued command acknowledgement changed');
        }
        if ($this->rows($connection, 'SELECT command FROM poller_command WHERE poller_id = ? AND action = ? AND BINARY command = BINARY ? AND time = ? AND last_updated = ?', [$collector, $command['action'], $command['command'], $command['time'], $command['last_updated']]) !== []) {
            throw new RuntimeException('Queued command acknowledgement could not be confirmed');
        }
    }

    private function command(array $command): void
    {
        if (!is_string($command['command'] ?? null) || !(is_int($command['action'] ?? null) || is_string($command['action'] ?? null))
            || preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D', (string) $command['action']) !== 1 || (int) $command['action'] > 255) {
            throw new RuntimeException('Queued command invalid');
        }
        foreach (['time', 'last_updated'] as $key) {
            if (!is_string($command[$key] ?? null) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $command[$key]) !== 1) {
                throw new RuntimeException('Queued command timestamp invalid');
            }
        }
    }

    private function sameCommand(array $current, array $expected): bool
    {
        return (string) $current['action'] === (string) $expected['action'] && $current['command'] === $expected['command']
            && $current['time'] === $expected['time'] && $current['last_updated'] === $expected['last_updated'];
    }

    private function session(PDO $connection, Closure $operation): mixed
    {
        if ($connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $connection->inTransaction()) {
            throw new RuntimeException('Owned collector transaction unavailable');
        }
        $variables = $this->rows($connection, "SHOW SESSION VARIABLES WHERE Variable_name IN ('transaction_isolation', 'tx_isolation', 'innodb_lock_wait_timeout', 'lock_wait_timeout')", []);
        $options = [];
        foreach ($variables as $row) {
            $options[strtolower($row['Variable_name'])] = $row['Value'];
        }
        $isolation = $options['transaction_isolation'] ?? $options['tx_isolation'] ?? null;
        $levels = ['READ-UNCOMMITTED', 'READ-COMMITTED', 'REPEATABLE-READ', 'SERIALIZABLE'];
        if (!in_array($isolation, $levels, true) || !isset($options['innodb_lock_wait_timeout'], $options['lock_wait_timeout'])
            || !ctype_digit($options['innodb_lock_wait_timeout']) || !ctype_digit($options['lock_wait_timeout'])) {
            throw new RuntimeException('Collector session options unavailable');
        }
        $failure = null;
        try {
            $this->execute($connection, 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ', []);
            $this->execute($connection, 'SET SESSION innodb_lock_wait_timeout = 30', []);
            $this->execute($connection, 'SET SESSION lock_wait_timeout = 30', []);
            $confirmed = $this->rows($connection, "SHOW SESSION VARIABLES WHERE Variable_name IN ('transaction_isolation', 'tx_isolation', 'innodb_lock_wait_timeout', 'lock_wait_timeout')", []);
            foreach ($confirmed as $row) {
                if (in_array(strtolower($row['Variable_name']), ['transaction_isolation', 'tx_isolation'], true) ? $row['Value'] !== 'REPEATABLE-READ' : $row['Value'] !== '30') {
                    throw new RuntimeException('Collector session options could not be confirmed');
                }
            }
            if (count($confirmed) !== count($variables)) {
                throw new RuntimeException('Collector session options could not be confirmed');
            }
            return $operation();
        } catch (Throwable $error) {
            $failure = $error;
            throw $error;
        } finally {
            $restoreFailure = null;
            foreach (['SET SESSION TRANSACTION ISOLATION LEVEL ' . str_replace('-', ' ', $isolation), 'SET SESSION innodb_lock_wait_timeout = ' . $options['innodb_lock_wait_timeout'], 'SET SESSION lock_wait_timeout = ' . $options['lock_wait_timeout']] as $sql) {
                try {
                    $this->execute($connection, $sql, []);
                } catch (Throwable $error) {
                    $restoreFailure ??= $error;
                }
            }
            try {
                $restored = $this->rows($connection, "SHOW SESSION VARIABLES WHERE Variable_name IN ('transaction_isolation', 'tx_isolation', 'innodb_lock_wait_timeout', 'lock_wait_timeout')", []);
                $restoredOptions = [];
                foreach ($restored as $row) {
                    $restoredOptions[strtolower($row['Variable_name'])] = $row['Value'];
                }
                if ($restoredOptions !== $options) {
                    throw new RuntimeException('Collector session restoration could not be confirmed');
                }
            } catch (Throwable $error) {
                $restoreFailure ??= $error;
            }
            if ($failure === null && $restoreFailure !== null) {
                throw new RuntimeException('Collector session restoration failed', 0, $restoreFailure);
            }
        }
    }

    private function execute(PDO $connection, string $sql, array $parameters): PDOStatement
    {
        $query = $connection->prepare($sql);
        if ($query === false || !$query->execute($parameters) || $query->errorCode() !== '00000') {
            throw new RuntimeException('Collector command query unavailable');
        }
        return $query;
    }

    private function rows(PDO $connection, string $sql, array $parameters): array
    {
        $query = $this->execute($connection, $sql, $parameters);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if ($query->errorCode() !== '00000' || !$query->closeCursor() || $query->errorCode() !== '00000') {
            throw new RuntimeException('Collector command read unavailable');
        }
        return $rows;
    }
}
