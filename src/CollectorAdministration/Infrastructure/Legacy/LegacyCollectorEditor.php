<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Infrastructure\Legacy;

use Kadupul\CollectorAdministration\Application\Port\CollectorEditor;
use Kadupul\CollectorAdministration\Application\Query\CollectorAccessDenied;
use Kadupul\CollectorAdministration\Infrastructure\Persistence\CollectorBulkAccessDenied;
use Kadupul\CollectorAdministration\Infrastructure\Persistence\PdoCollectorOperatorAuthorization;
use Kadupul\CollectorAdministration\Domain\CollectorDetails;
use Kadupul\CollectorAdministration\Domain\CollectorRevision;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\DatabaseConnection;

final readonly class LegacyCollectorEditor implements CollectorEditor
{
    public function __construct(
        private DatabaseConnection $database,
        private ConsoleAccess $access,
        private AuditTrail $audit,
        private CollectorRevisionKey $revisionKey,
    ) {}

    public function find(int $id): ?CollectorDetails
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Invalid collector identifier.');
        }
        $query = $this->database->get()->prepare('SELECT id, name, hostname, timezone, notes, processes, threads,
            sync_interval, dbdefault, dbhost, dbuser, dbport, dbretries, dbssl, dbsslkey, dbsslcert, dbsslca,
            dbpass, CASE WHEN dbpass <> \'\' THEN 1 ELSE 0 END AS password_configured
            FROM poller WHERE id = ?');
        $query->execute([$id]);
        $row = $query->fetch();
        if (!$row) {
            return null;
        }

        $settings = array_intersect_key($row, array_flip(['dbdefault', 'dbhost', 'dbuser', 'dbport', 'dbretries', 'dbssl', 'dbsslkey', 'dbsslcert', 'dbsslca']));
        $values = [
            'name' => (string) ($row['name'] ?? ''), 'hostname' => (string) $row['hostname'],
            'timezone' => (string) ($row['timezone'] ?? ''), 'notes' => (string) ($row['notes'] ?? ''),
            'processes' => (int) ($row['processes'] ?? 0), 'threads' => (int) ($row['threads'] ?? 0),
            'sync_interval' => (int) ($row['sync_interval'] ?? 0),
        ] + $settings;
        return new CollectorDetails(
            (int) $row['id'],
            (string) ($row['name'] ?? ''),
            (string) $row['hostname'],
            (string) ($row['timezone'] ?? ''),
            (string) ($row['notes'] ?? ''),
            (int) ($row['processes'] ?? 0),
            (int) ($row['threads'] ?? 0),
            (int) ($row['sync_interval'] ?? 0),
            $settings,
            (bool) $row['password_configured'],
            CollectorRevision::fromValues($values, (string) $row['dbpass'], $this->revisionKey->get()),
        );
    }

    public function save(int $actorId, ?int $id, #[\SensitiveParameter] array $values): int
    {
        $connection = $this->database->get();
        $target = $id === null ? 'new' : (string) $id;
        $decision = AuditEvent::DENIED;
        $outcome = AuditEvent::DENIED;
        try {
            $connection->beginTransaction();
            $actor = $this->access->consoleActor();
            if ($actor === null || $actor->id !== $actorId || !$this->access->canManageDevices($actor)) {
                throw new CollectorAccessDenied($actor === null);
            }
            try {
                (new PdoCollectorOperatorAuthorization())->assertCanManage($connection, $actorId);
            } catch (CollectorBulkAccessDenied) {
                throw new CollectorAccessDenied(false);
            }
            $decision = AuditEvent::ALLOWED;
            $outcome = AuditEvent::FAILED;

            $existing = null;
            if ($id !== null) {
                $lockingRead = $connection->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
                $lookup = $connection->prepare('SELECT id, dbpass FROM poller WHERE id = ?' . $lockingRead);
                $lookup->execute([$id]);
                $existing = $lookup->fetch();
                if (!$existing) {
                    throw new \InvalidArgumentException('Collector not found.');
                }
            }

            $this->validateValues($id, $values);
            if ($existing) {
                $current = $this->revisionValues($connection, $id);
                $revision = CollectorRevision::fromValues($current, (string) $current['dbpass'], $this->revisionKey->get());
                if (!is_string($values['revision'] ?? null) || !hash_equals($revision, $values['revision'])) {
                    throw new \InvalidArgumentException('Collector changed since you opened this form. Reload before saving.');
                }
            }
            $this->assertNoDuplicateHostname($connection, $id, (string) $values['hostname'], 'hostname');
            if ($id !== 1) {
                $dbHost = (string) ($values['dbhost'] ?? '');
                $this->assertNoDuplicateHostname($connection, $id, $dbHost, 'dbhost');
                if (strtolower(trim($dbHost)) === 'localhost' || $this->hasDatabaseHostConflict($connection, $id, $dbHost)) {
                    throw new \InvalidArgumentException('The remote database host conflicts with another collector or uses localhost.');
                }
            }

            $common = [
                (string) $values['name'], (string) $values['hostname'], (string) $values['timezone'], (string) $values['notes'],
                (int) $values['processes'], (int) $values['threads'],
            ];
            if (($id ?? 0) === 1) {
                $statement = $connection->prepare('UPDATE poller SET name = ?, hostname = ?, timezone = ?, notes = ?, processes = ?, threads = ? WHERE id = 1');
                $statement->execute($common);
                $savedId = 1;
            } else {
                $settings = [
                    (string) ($values['dbdefault'] ?? ''), (string) ($values['dbhost'] ?? ''),
                    (string) ($values['dbuser'] ?? ''), (string) ($values['dbpass'] ?? ''),
                    (int) ($values['dbport'] ?? 0), (int) ($values['dbretries'] ?? 0),
                    !empty($values['dbssl']) ? 'on' : '', (string) ($values['dbsslkey'] ?? ''),
                    (string) ($values['dbsslcert'] ?? ''), (string) ($values['dbsslca'] ?? ''),
                ];
                if ($existing && $settings[3] === '') {
                    $settings[3] = (string) $existing['dbpass'];
                }
                if ($id === null) {
                    $statement = $connection->prepare('INSERT INTO poller (name, hostname, timezone, notes, processes, threads, sync_interval, dbdefault, dbhost, dbuser, dbpass, dbport, dbretries, dbssl, dbsslkey, dbsslcert, dbsslca)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $statement->execute([...$common, (int) ($values['sync_interval'] ?? 0), ...$settings]);
                    $savedId = (int) $connection->lastInsertId();
                    if ($savedId < 1) {
                        throw new \RuntimeException('Collector creation did not return an identifier.');
                    }
                    $target = (string) $savedId;
                } else {
                    $statement = $connection->prepare('UPDATE poller SET name = ?, hostname = ?, timezone = ?, notes = ?, processes = ?, threads = ?, sync_interval = ?, dbdefault = ?, dbhost = ?, dbuser = ?, dbpass = ?, dbport = ?, dbretries = ?, dbssl = ?, dbsslkey = ?, dbsslcert = ?, dbsslca = ? WHERE id = ?');
                    $statement->execute([...$common, (int) ($values['sync_interval'] ?? 0), ...$settings, $id]);
                    $savedId = $id;
                }
            }

            if (!$connection->commit()) {
                throw new \RuntimeException('Collector save could not be confirmed.');
            }
            $outcome = AuditEvent::SUCCEEDED;
            return $savedId;
        } catch (\Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        } finally {
            try {
                $this->audit->record(new AuditEvent(bin2hex(random_bytes(16)), $actorId, $id === null ? 'collector.create' : 'collector.edit', 'collector', $target, $decision, $outcome));
            } catch (\Throwable) {
                // Audit storage must never expose request data or change the save result.
            }
        }
    }

    private function assertNoDuplicateHostname(\PDO $connection, ?int $id, string $hostname, string $column): void
    {
        $candidate = $this->hostnameAliases($hostname);
        if ($candidate === []) {
            return;
        }
        $lock = $connection->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $query = $connection->prepare('SELECT id, ' . $column . ' AS hostname FROM poller WHERE id <> ?' . $lock);
        $query->execute([$id ?? 0]);
        while ($row = $query->fetch()) {
            foreach ($this->hostnameAliases((string) $row['hostname']) as $existing) {
                if (in_array($existing, $candidate, true)) {
                    throw new \InvalidArgumentException($column === 'hostname' ? 'This collector hostname is already in use.' : 'This remote database hostname is already in use.');
                }
            }
        }
    }

    /** @return list<string> */
    private function hostnameAliases(string $hostname): array
    {
        $addresses = [];
        $names = [];
        if (filter_var($hostname, FILTER_VALIDATE_IP)) {
            $reverse = @gethostbyaddr($hostname);
            if ($reverse !== false && $reverse !== $hostname) {
                $names[] = $reverse;
            } else {
                $addresses[] = $hostname;
            }
            $addresses[] = $hostname;
        } elseif (str_contains($hostname, '.')) {
            $resolved = @gethostbyname($hostname);
            if ($resolved !== $hostname) {
                $addresses[] = $resolved;
            }
            foreach (@dns_get_record($hostname) ?: [] as $record) {
                if (isset($record['target'])) {
                    $names[] = (string) $record['target'];
                }
                if (isset($record['host'])) {
                    $names[] = (string) $record['host'];
                }
                if (isset($record['ip'])) {
                    $addresses[] = (string) $record['ip'];
                }
            }
            $names[] = $hostname;
        } else {
            $resolved = @gethostbyname($hostname);
            if ($resolved !== $hostname) {
                $addresses[] = $resolved;
            }
        }
        $aliases = array_map('strtolower', $addresses);
        foreach (array_unique(array_map('strtolower', $names)) as $name) {
            $aliases[] = $name;
            $aliases[] = explode('.', $name)[0];
        }
        if ($hostname !== '') {
            $aliases[] = strtolower($hostname);
        }
        return array_values(array_unique($aliases));
    }

    private function hasDatabaseHostConflict(\PDO $connection, ?int $id, string $host): bool
    {
        $query = $connection->prepare('SELECT id FROM poller WHERE dbhost LIKE ? AND id <> ? LIMIT 1');
        $query->execute([$host . '%', $id ?? 0]);
        return $query->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $values */
    private function validateValues(?int $id, array $values): void
    {
        foreach (['name' => 30, 'hostname' => 100, 'timezone' => 40, 'notes' => 1024] as $field => $limit) {
            $value = $values[$field] ?? null;
            if (!is_string($value) || strlen($value) > $limit || preg_match('//u', $value) !== 1 || str_contains($value, "\0") || ($field !== 'notes' && trim($value) === '')) {
                throw new \InvalidArgumentException('Collector ' . $field . ' is invalid.');
            }
        }
        foreach (['processes', 'threads'] as $field) {
            if (!is_int($values[$field] ?? null) || $values[$field] < 0 || $values[$field] > 9999) {
                throw new \InvalidArgumentException('Collector ' . $field . ' is invalid.');
            }
        }
        if ($id !== 1) {
            if (!is_int($values['sync_interval'] ?? null) || !in_array($values['sync_interval'], [0, 1800, 3600, 7200, 14400, 28800, 57600, 86400], true)) {
                throw new \InvalidArgumentException('Collector sync interval is invalid.');
            }
            foreach (['dbdefault' => 20, 'dbhost' => 64, 'dbuser' => 20, 'dbpass' => 64, 'dbsslkey' => 255, 'dbsslcert' => 255, 'dbsslca' => 255] as $field => $limit) {
                $value = $values[$field] ?? null;
                if (!is_string($value) || strlen($value) > $limit || preg_match('//u', $value) !== 1 || str_contains($value, "\0")) {
                    throw new \InvalidArgumentException('Remote database ' . $field . ' is invalid.');
                }
            }
            foreach (['dbport' => [1, 65535], 'dbretries' => [0, 99999]] as $field => [$minimum, $maximum]) {
                if (!is_int($values[$field] ?? null) || $values[$field] < $minimum || $values[$field] > $maximum) {
                    throw new \InvalidArgumentException('Remote database ' . $field . ' is invalid.');
                }
            }
            if (!is_bool($values['dbssl'] ?? null)) {
                throw new \InvalidArgumentException('Remote database SSL setting is invalid.');
            }
        }
    }

    /** @return array<string, mixed> */
    private function revisionValues(\PDO $connection, int $id): array
    {
        $query = $connection->prepare('SELECT name, hostname, timezone, notes, processes, threads, sync_interval,
            dbdefault, dbhost, dbuser, dbport, dbretries, dbssl, dbsslkey, dbsslcert, dbsslca, dbpass FROM poller WHERE id = ?');
        $query->execute([$id]);
        $values = $query->fetch();
        if (!$values) {
            throw new \InvalidArgumentException('Collector not found.');
        }
        foreach (['processes', 'threads', 'sync_interval', 'dbport', 'dbretries'] as $field) {
            $values[$field] = (int) ($values[$field] ?? 0);
        }
        return $values;
    }
}
