<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Port\SnmpOptionStore;
use Kadupul\Automation\Domain\SnmpOptionEntries;
use Kadupul\Automation\Domain\SnmpOptionEntry;
use Kadupul\Automation\Domain\SnmpOptionEntryChange;
use Kadupul\Automation\Domain\SnmpOptionSet;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Infrastructure\Persistence\AuditedPrimaryWrite;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * automation_snmp and automation_snmp_items. Every write locks the set row
 * first, so concurrent edits, appends and moves of one set are serialized,
 * and the legacy bulk delete of the set waits for them to commit.
 */
#[AsAlias(SnmpOptionStore::class)]
final readonly class DbalSnmpOptionStore implements SnmpOptionStore
{
    // Every table a write changes or locks, with the permission tables the actor recheck reads.
    private const array WRITE_TABLES = ['automation_snmp', 'automation_snmp_items', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_realm', 'user_auth_group_members', 'settings'];
    private const string SET_STALE = 'The SNMP Option Set changed since you opened this form. Reload before saving.';
    private const string ENTRY_STALE = 'The SNMP entry changed since you opened this form. Reload before saving.';
    private const string ORDER_STALE = 'The SNMP entry order changed since you opened this page. Reload before moving.';
    private const string ENTRY_COLUMNS = 'id, snmp_id, sequence, snmp_version, snmp_community, snmp_port, snmp_timeout, snmp_retries, max_oids, bulk_walk_size,
        snmp_username, snmp_password, snmp_auth_protocol, snmp_priv_passphrase, snmp_priv_protocol, snmp_context, snmp_engine_id';

    private AuditedPrimaryWrite $writes;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
        AutomationTemplateAccess $access,
        AuditTrail $audit,
        LegacyConfiguration $configuration,
    ) {
        // SNMP Option Sets share the automation realm, so the Device Rules recheck applies unchanged.
        $this->writes = new AuditedPrimaryWrite($database, $audit, $configuration, $access->assertCurrent(...), self::WRITE_TABLES, 'SNMP Option Set', 'SNMP Option Sets', 'automation.snmp_option', 'automation_snmp_option');
    }

    public function entryDefaults(): array
    {
        $settings = [];
        foreach ($this->database->fetchAllAssociative("SELECT name, value FROM settings WHERE name IN ('snmp_version', 'snmp_port', 'snmp_timeout', 'snmp_retries', 'max_get_size', 'snmp_auth_protocol', 'snmp_priv_protocol')") as $row) {
            $settings[self::text($row['name'])] = self::text($row['value']);
        }
        $integer = static function (string $name, int $fallback, ?array $allowed = null, int $min = 0, int $max = PHP_INT_MAX) use ($settings): int {
            $value = filter_var($settings[$name] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
            return !is_int($value) || ($allowed !== null && !in_array($value, $allowed, true)) ? $fallback : $value;
        };
        $choice = static fn(string $name, array $allowed): string => in_array($settings[$name] ?? null, $allowed, true) ? $settings[$name] : '[None]';
        return [
            'snmp_version' => $integer('snmp_version', 2, SnmpOptionEntryChange::VERSIONS),
            'snmp_port' => $integer('snmp_port', 161, null, 1, 65535),
            'snmp_timeout' => $integer('snmp_timeout', 500, null, 0, SnmpOptionEntryChange::TIMEOUT_MAX),
            'snmp_retries' => $integer('snmp_retries', 3, null, 0, SnmpOptionEntryChange::RETRIES_MAX),
            'max_oids' => $integer('max_get_size', 10, SnmpOptionEntryChange::MAX_OIDS),
            // The legacy form defaulted this one field to Auto Detect/Set on first Re-Index.
            'bulk_walk_size' => 0,
            'snmp_auth_protocol' => $choice('snmp_auth_protocol', SnmpOptionEntryChange::AUTH_PROTOCOLS),
            'snmp_priv_protocol' => $choice('snmp_priv_protocol', SnmpOptionEntryChange::PRIVACY_PROTOCOLS),
            'snmp_context' => '',
            'snmp_engine_id' => '',
        ];
    }

    public function findSet(int $id): ?SnmpOptionSet
    {
        $row = $this->database->fetchAssociative('SELECT id, name FROM automation_snmp WHERE id = ?', [$id]);
        return $row === false ? null : new SnmpOptionSet(self::int($row['id']), self::text($row['name']), SnmpOptionSet::revisionOf($row));
    }

    public function entries(int $setId): SnmpOptionEntries
    {
        $rows = $this->database->fetchAllAssociative('SELECT ' . self::ENTRY_COLUMNS . ' FROM automation_snmp_items WHERE snmp_id = ? ORDER BY sequence, id', [$setId]);
        return new SnmpOptionEntries(array_map(self::entry(...), $rows), SnmpOptionEntry::orderOf($rows));
    }

    public function findEntry(int $setId, int $entryId): ?SnmpOptionEntry
    {
        $row = $this->database->fetchAssociative('SELECT ' . self::ENTRY_COLUMNS . ' FROM automation_snmp_items WHERE id = ? AND snmp_id = ?', [$entryId, $setId]);
        return $row === false ? null : self::entry($row);
    }

    public function saveSet(int $actorId, ?int $id, string $name, ?string $revision): int
    {
        SnmpOptionSet::validateName($name);
        return $this->writes->run($actorId, $id === null ? 'create' : 'edit', 'automation_snmp_option', function () use ($id, $name, $revision): int {
            if ($id === null) {
                $this->database->insert('automation_snmp', ['name' => $name]);
                $created = (int) $this->database->lastInsertId();
                if ($created < 1) {
                    throw new \RuntimeException('SNMP Option Set creation was not confirmed.');
                }
                return $created;
            }
            $row = $this->lockSet($id);
            if ($revision === null || !hash_equals(SnmpOptionSet::revisionOf($row), $revision)) {
                throw new \InvalidArgumentException(self::SET_STALE);
            }
            $this->database->update('automation_snmp', ['name' => $name], ['id' => $id]);
            return $id;
        });
    }

    public function saveEntry(int $actorId, int $setId, ?int $entryId, SnmpOptionEntryChange $change, ?string $revision): int
    {
        return $this->writes->run($actorId, $entryId === null ? 'entry_create' : 'entry_edit', 'automation_snmp_option', function () use ($setId, $entryId, $change, $revision): int {
            $this->lockSet($setId);
            if ($entryId === null) {
                $values = $change->resolve([]);
                $sequence = self::int($this->database->fetchOne('SELECT COALESCE(MAX(sequence), 0) FROM automation_snmp_items WHERE snmp_id = ?', [$setId])) + 1;
                $this->database->insert('automation_snmp_items', ['snmp_id' => $setId, 'sequence' => $sequence] + $values);
                $created = (int) $this->database->lastInsertId();
                if ($created < 1) {
                    throw new \RuntimeException('SNMP entry creation was not confirmed.');
                }
                return $created;
            }
            $stored = $this->lockEntry($setId, $entryId, $revision ?? '');
            $this->database->update('automation_snmp_items', $change->resolve($stored), ['id' => $entryId, 'snmp_id' => $setId]);
            return $entryId;
        });
    }

    public function deleteEntry(int $actorId, int $setId, int $entryId, string $revision): void
    {
        $this->writes->run($actorId, 'entry_delete', 'automation_snmp_option', function () use ($setId, $entryId, $revision): void {
            $this->lockSet($setId);
            $this->lockEntry($setId, $entryId, $revision);
            if ($this->database->executeStatement('DELETE FROM automation_snmp_items WHERE id = ? AND snmp_id = ?', [$entryId, $setId]) !== 1) {
                throw new \RuntimeException('SNMP entry deletion was not confirmed.');
            }
        });
    }

    public function moveEntry(int $actorId, int $setId, int $entryId, int $offset, string $order): void
    {
        if (!in_array($offset, [-1, 1], true)) {
            throw new \InvalidArgumentException('Invalid SNMP entry order.');
        }
        $this->writes->run($actorId, 'entry_move', 'automation_snmp_option', function () use ($setId, $entryId, $offset, $order): void {
            $this->lockSet($setId);
            $rows = $this->database->fetchAllAssociative('SELECT id, sequence FROM automation_snmp_items WHERE snmp_id = ? ORDER BY sequence, id' . $this->forUpdate(), [$setId]);
            if (!hash_equals(SnmpOptionEntry::orderOf($rows), $order)) {
                throw new \InvalidArgumentException(self::ORDER_STALE);
            }
            $ids = array_map(static fn(array $row): int => self::int($row['id']), $rows);
            $current = array_combine($ids, array_map(static fn(array $row): int => self::int($row['sequence']), $rows));
            $position = array_search($entryId, $ids, true);
            if (!is_int($position) || !isset($ids[$position + $offset])) {
                throw new \InvalidArgumentException('Invalid SNMP entry order.');
            }
            [$ids[$position], $ids[$position + $offset]] = [$ids[$position + $offset], $ids[$position]];
            // Renumber densely in discovery order, as the legacy drag and drop did;
            // ties that legacy left unordered are settled by ID, as listed.
            foreach ($ids as $index => $id) {
                if ($current[$id] !== $index + 1) {
                    $this->database->update('automation_snmp_items', ['sequence' => $index + 1], ['id' => $id, 'snmp_id' => $setId]);
                }
            }
        });
    }

    /** @return array<string, mixed> */
    private function lockSet(int $id): array
    {
        $row = $this->database->fetchAssociative('SELECT id, name FROM automation_snmp WHERE id = ?' . $this->forUpdate(), [$id]);
        if ($row === false) {
            throw new \InvalidArgumentException('SNMP Option Set not found.');
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function lockEntry(int $setId, int $entryId, string $revision): array
    {
        $row = $this->database->fetchAssociative('SELECT ' . self::ENTRY_COLUMNS . ' FROM automation_snmp_items WHERE id = ? AND snmp_id = ?' . $this->forUpdate(), [$entryId, $setId]);
        if ($row === false) {
            throw new \InvalidArgumentException('SNMP entry not found.');
        }
        if (!hash_equals(SnmpOptionEntry::revisionOf($row), $revision)) {
            throw new \InvalidArgumentException(self::ENTRY_STALE);
        }
        return $row;
    }

    /** @param array<string, mixed> $row */
    private static function entry(array $row): SnmpOptionEntry
    {
        // Legacy rows for SNMP versions 1 and 2 may hold an empty protocol.
        $protocol = static fn(mixed $value): string => in_array(self::text($value), ['', '0'], true) ? '[None]' : self::text($value);
        return new SnmpOptionEntry(
            self::int($row['id']),
            self::int($row['snmp_id']),
            self::int($row['sequence']),
            self::int($row['snmp_version']),
            self::int($row['snmp_port']),
            self::int($row['snmp_timeout']),
            self::int($row['snmp_retries']),
            self::int($row['max_oids'] ?? 10),
            self::int($row['bulk_walk_size'] ?? -1),
            $protocol($row['snmp_auth_protocol'] ?? ''),
            $protocol($row['snmp_priv_protocol'] ?? ''),
            self::text($row['snmp_context'] ?? ''),
            self::text($row['snmp_engine_id'] ?? ''),
            SnmpOptionEntry::revisionOf($row),
        );
    }

    private static function int(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new \UnexpectedValueException('The SNMP option tables returned an unexpected row.');
        }
        return (int) $value;
    }

    /** NULL columns read as empty text, as the legacy forms showed them. */
    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : '');
    }

    private function forUpdate(): string
    {
        return $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' FOR UPDATE' : '';
    }
}
