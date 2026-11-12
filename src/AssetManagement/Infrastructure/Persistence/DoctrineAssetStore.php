<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\AssetManagement\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kadupul\AssetManagement\Application\Port\AssetStore;
use Kadupul\AssetManagement\Domain\AssetError;
use Kadupul\AssetManagement\Domain\AssetData;

/**
 * Stores the asset register in the database as one registry row plus record, event and observation tables.
 * Writes are confirmed by reading them back.
 */
final readonly class DoctrineAssetStore implements AssetStore
{
    /**
     * Binds the store to the database connection.
     */
    public function __construct(private Connection $database) {}

    /**
     * Runs the operation in a transaction that locks the registry row.
     * On MySQL it first requires utf8mb4, strict mode and InnoDB tables.
     *
     * @throws AssetError when the caller already holds a transaction, storage is unsafe or missing, or the commit outcome is unknown (uncertain)
     */
    public function atomic(\Closure $operation): mixed
    {
        if ($this->database->getTransactionNestingLevel() !== 0) {
            throw new AssetError('caller_transaction');
        }
        $mysql = $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        if ($mysql) {
            $this->database->executeStatement('SET NAMES utf8mb4');
            $this->database->executeStatement("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
            $session = $this->database->fetchNumeric('SELECT @@character_set_connection, @@SESSION.sql_mode');
            if ($session === false || $session[0] !== 'utf8mb4' || !in_array('STRICT_ALL_TABLES', explode(',', AssetData::string($session[1])), true)) {
                throw new AssetError('unsafe_storage');
            }
            foreach (['asset_registry', 'asset_record', 'asset_event', 'asset_observation'] as $table) {
                $definition = $this->database->fetchNumeric('SHOW CREATE TABLE ' . $table);
                if ($definition === false || !str_contains(AssetData::string($definition[1]), 'ENGINE=InnoDB') || str_contains(AssetData::string($definition[1]), 'TEMPORARY')) {
                    throw new AssetError('unsafe_storage');
                }
            }
        }
        $this->database->beginTransaction();
        try {
            $revision = $this->database->fetchOne('SELECT revision FROM asset_registry WHERE id = 1' . ($mysql ? ' FOR UPDATE' : ''));
            if ($revision === false) {
                throw new AssetError('schema_unavailable');
            }
            $result = $operation();
            try {
                $this->database->commit();
            } catch (\Throwable) {
                throw new AssetError('uncertain');
            }
            return $result;
        } catch (\Throwable $error) {
            try {
                if ($this->database->isTransactionActive()) {
                    $this->database->rollBack();
                }
            } catch (\Throwable) {
                // Preserve the failing operation, including an uncertain commit.
            }
            throw $error;
        }
    }

    /**
     * Reads the whole register at one revision.
     *
     * @throws AssetError when the schema is missing, stored data is invalid, there are more than 4500 records, or the revision changed during the read
     */
    public function snapshot(): array
    {
        $row = $this->database->fetchAssociative('SELECT revision, policy, sources FROM asset_registry WHERE id = 1');
        if ($row === false) {
            throw new AssetError('schema_unavailable');
        }
        $state = ['revision' => AssetData::number($row['revision']), 'policy' => AssetData::policy($this->decode(AssetData::string($row['policy']))), 'sources' => AssetData::sources($this->decode(AssetData::string($row['sources']))), 'assets' => [], 'identities' => [], 'dependencies' => []];
        $rows = $this->database->fetchAllAssociative('SELECT kind, record_id, data FROM asset_record ORDER BY kind, record_id LIMIT 4501');
        if (count($rows) > 4500) {
            throw new AssetError('capacity');
        }
        foreach ($rows as $record) {
            if (!in_array($record['kind'], ['assets', 'identities', 'dependencies'], true)) {
                throw new AssetError('invalid_storage');
            }
            $id = AssetData::string($record['record_id']);
            $data = $this->decode(AssetData::string($record['data']));
            switch ($record['kind']) {
                case 'assets': $state['assets'][$id] = AssetData::asset($data);
                    break;
                case 'identities': $state['identities'][$id] = AssetData::identity($data);
                    break;
                case 'dependencies': $state['dependencies'][$id] = ['asset_id' => \Kadupul\AssetManagement\Domain\AssetFields::id($data['asset_id'] ?? null), 'depends_on' => \Kadupul\AssetManagement\Domain\AssetFields::id($data['depends_on'] ?? null)];
                    break;
            }
        }
        // A reader must not combine a registry revision with records from another commit.
        if (AssetData::number($this->database->fetchOne('SELECT revision FROM asset_registry WHERE id = 1')) !== $state['revision']) {
            throw new AssetError('stale_revision');
        }
        return $state;
    }

    /**
     * Writes the differences between the two snapshots, then reads the register back and compares it.
     *
     * @throws AssetError when the stored revision no longer matches the before snapshot or a write cannot be confirmed
     */
    public function save(array $before, array $after): void
    {
        foreach (['assets', 'identities', 'dependencies'] as $kind) {
            foreach ($before[$kind] as $id => $record) {
                if (!isset($after[$kind][$id])) {
                    $this->database->delete('asset_record', ['kind' => $kind, 'record_id' => $id]);
                }
            }
            foreach ($after[$kind] as $id => $record) {
                if (($before[$kind][$id] ?? null) === $record) {
                    continue;
                }
                $data = json_encode($record, JSON_THROW_ON_ERROR);
                if (isset($before[$kind][$id])) {
                    $this->database->update('asset_record', ['data' => $data], ['kind' => $kind, 'record_id' => $id]);
                } else {
                    $this->database->insert('asset_record', ['kind' => $kind, 'record_id' => $id, 'data' => $data]);
                }
                if ($this->database->fetchOne('SELECT data FROM asset_record WHERE kind = ? AND record_id = ?', [$kind, $id]) !== $data) {
                    throw new AssetError('write_unconfirmed');
                }
            }
        }
        if ($this->database->update('asset_registry', ['revision' => $after['revision'], 'policy' => json_encode($after['policy'], JSON_THROW_ON_ERROR), 'sources' => json_encode($after['sources'], JSON_THROW_ON_ERROR)], ['id' => 1, 'revision' => $before['revision']]) !== 1) {
            throw new AssetError('stale_revision');
        }
        $stored = $this->snapshot();
        foreach (['assets', 'identities', 'dependencies'] as $kind) {
            ksort($after[$kind]);
        }
        if ($stored !== $after) {
            throw new AssetError('write_unconfirmed');
        }
    }

    /**
     * Returns the actor, command hash and result recorded for the request id, or null when the request was never applied.
     */
    public function receipt(string $requestId): ?array
    {
        $data = $this->database->fetchOne('SELECT data FROM asset_event WHERE request_id = ?', [$requestId]);
        if ($data === false) {
            return null;
        }
        $event = $this->decode(AssetData::string($data));
        return ['actor_id' => AssetData::number($event['actor_id'] ?? null), 'command_hash' => AssetData::string($event['command_hash'] ?? null), 'result' => AssetData::result(AssetData::object($event['result'] ?? null))];
    }

    /**
     * Appends the event to the audit journal and confirms it was stored.
     *
     * @throws AssetError when the stored event differs from the one written
     */
    public function journal(array $event): void
    {
        $data = json_encode($event, JSON_THROW_ON_ERROR);
        $this->database->insert('asset_event', ['request_id' => $event['request_id'], 'data' => $data]);
        if ($this->database->fetchOne('SELECT data FROM asset_event WHERE request_id = ?', [$event['request_id']]) !== $data) {
            throw new AssetError('write_unconfirmed');
        }
    }

    /**
     * Records each observation under its run and confirms it was stored.
     *
     * @throws AssetError when a stored observation differs from the one written
     */
    public function observe(array $observations): void
    {
        foreach ($observations as $observation) {
            $data = json_encode($observation, JSON_THROW_ON_ERROR);
            $this->database->insert('asset_observation', ['run_id' => $observation['run_id'], 'identity_id' => $observation['id'], 'data' => $data]);
            if ($this->database->fetchOne('SELECT data FROM asset_observation WHERE run_id = ? AND identity_id = ?', [$observation['run_id'], $observation['id']]) !== $data) {
                throw new AssetError('write_unconfirmed');
            }
        }
    }

    /**
     * Returns up to $limit events older than the sequence number $before, newest first.
     *
     * @throws AssetError when $before is below 1 or $limit is outside 1 to 50
     */
    public function history(int $before, int $limit): array
    {
        if ($before < 1 || $limit < 1 || $limit > 50) {
            throw new AssetError('invalid_fields');
        }
        $rows = $this->database->fetchAllAssociative('SELECT sequence, data FROM asset_event WHERE sequence < ? ORDER BY sequence DESC LIMIT ' . $limit, [$before]);
        $history = [];
        foreach ($rows as $row) {
            $event = $this->decode(AssetData::string($row['data']));
            $history[] = ['sequence' => AssetData::number($row['sequence']), 'request_id' => AssetData::string($event['request_id'] ?? null), 'actor' => AssetData::string($event['actor'] ?? null), 'action' => AssetData::string($event['action'] ?? null), 'evidence' => AssetData::string($event['evidence'] ?? null), 'occurred_at' => AssetData::timestamp($event['occurred_at'] ?? null), 'changes' => AssetData::object($event['changes'] ?? null)];
        }
        return $history;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $data): array
    {
        $value = json_decode($data, true, 32, JSON_THROW_ON_ERROR);
        return AssetData::object($value);
    }
}
