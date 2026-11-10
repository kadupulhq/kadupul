<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Query\AutomationTemplateAccessDenied;
use Kadupul\Automation\Domain\SnmpOptionEntry;
use Kadupul\Automation\Domain\SnmpOptionEntryChange;
use Kadupul\Automation\Infrastructure\Persistence\DbalSnmpOptionStore;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;

final class SnmpOptionStoreTest extends TestCase
{
    private const array V2 = ['snmp_version' => 2, 'snmp_port' => 161, 'snmp_timeout' => 500, 'snmp_retries' => 3, 'max_oids' => 10, 'bulk_walk_size' => -1,
        'snmp_auth_protocol' => '[None]', 'snmp_priv_protocol' => '[None]', 'snmp_context' => '', 'snmp_engine_id' => ''];
    private const array NO_CREDENTIALS = ['snmp_community' => '', 'snmp_username' => '', 'snmp_password' => '', 'snmp_priv_passphrase' => ''];

    private Connection $database;
    private DbalSnmpOptionStore $store;
    private bool $revoked = false;
    /** @var list<bool> */
    private array $checkedInTransaction = [];
    /** @var list<AuditEvent> */
    private array $audit = [];

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            "CREATE TABLE automation_snmp (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '')",
            "CREATE TABLE automation_snmp_items (id INTEGER PRIMARY KEY AUTOINCREMENT, snmp_id INT NOT NULL DEFAULT 0, sequence INT NOT NULL DEFAULT 0,
                snmp_version INT NOT NULL DEFAULT 1, snmp_community TEXT NOT NULL, snmp_port INT NOT NULL DEFAULT 161, snmp_timeout INT NOT NULL DEFAULT 500,
                snmp_retries INT NOT NULL DEFAULT 3, max_oids INT DEFAULT 10, bulk_walk_size INT DEFAULT -1, snmp_username TEXT DEFAULT NULL,
                snmp_password TEXT DEFAULT NULL, snmp_auth_protocol TEXT DEFAULT '', snmp_priv_passphrase TEXT DEFAULT '', snmp_priv_protocol TEXT DEFAULT '',
                snmp_context TEXT DEFAULT '', snmp_engine_id TEXT DEFAULT '')",
            'CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)',
        ] as $statement) {
            $this->database->executeStatement($statement);
        }
        $access = $this->createMock(AutomationTemplateAccess::class);
        $access->method('assertCurrent')->willReturnCallback(function (): void {
            $this->checkedInTransaction[] = $this->database->isTransactionActive();
            if ($this->revoked) {
                throw new AutomationTemplateAccessDenied();
            }
        });
        $audit = $this->createMock(AuditTrail::class);
        $audit->method('record')->willReturnCallback(function (AuditEvent $event): void {
            $this->audit[] = $event;
        });
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);
        $this->store = new DbalSnmpOptionStore($this->database, $access, $audit, $configuration, 'test-revision-key');
    }

    public function testSetsAreCreatedRenamedAndRefuseStaleOrInvalidNames(): void
    {
        $id = $this->store->saveSet(7, null, 'Core <b> & "edge"', null);
        $set = $this->store->findSet($id);
        // Stored as entered: legacy neither trimmed nor encoded the name.
        self::assertSame('Core <b> & "edge"', $set->name);
        $this->store->saveSet(7, $id, 'Renamed', $set->revision);
        self::assertSame('Renamed', $this->database->fetchOne('SELECT name FROM automation_snmp WHERE id = ?', [$id]));
        foreach ([$set->revision, null, ''] as $stale) {
            try {
                $this->store->saveSet(7, $id, 'Stale', $stale);
                self::fail('A stale SNMP Option Set edit was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('changed since', $error->getMessage());
            }
        }
        foreach (['', "a\0b", "\xff", str_repeat('n', 101)] as $name) {
            try {
                $this->store->saveSet(7, null, $name, null);
                self::fail('Accepted an invalid name.');
            } catch (\InvalidArgumentException) {
            }
        }
        $this->store->saveSet(7, null, str_repeat('é', 100), null);
        self::assertSame(2, (int) $this->database->fetchOne('SELECT COUNT(*) FROM automation_snmp'));
        self::assertSame(['automation.snmp_option.create', 'automation.snmp_option.edit'], [$this->audit[0]->action, $this->audit[1]->action]);
        self::assertSame([true, true], array_slice($this->checkedInTransaction, 0, 2));
    }

    public function testEntriesAppendKeepOrReplaceCredentialsAndNeverReturnThem(): void
    {
        $set = $this->store->saveSet(7, null, 'Core', null);
        // The installer and legacy saves leave ties and gaps in real tables.
        $this->database->insert('automation_snmp_items', ['snmp_id' => $set, 'sequence' => 4, 'snmp_version' => 1, 'snmp_community' => 'legacy', 'snmp_auth_protocol' => '']);
        $created = $this->store->saveEntry(7, $set, null, new SnmpOptionEntryChange(self::V2, false, ['snmp_community' => 'secret-community'] + self::NO_CREDENTIALS), null);
        $row = $this->database->fetchAssociative('SELECT * FROM automation_snmp_items WHERE id = ?', [$created]);
        self::assertSame(['5', 'secret-community', '2'], [(string) $row['sequence'], $row['snmp_community'], (string) $row['snmp_version']]);

        $entry = $this->store->findEntry($set, $created);
        self::assertStringNotContainsString('secret-community', serialize($entry));
        self::assertStringNotContainsString('secret-community', serialize($this->store->entries($set)));
        // The revision reaches the page, so it must not be a digest anyone can recompute from guessed credentials.
        $stored = $this->database->fetchAssociative('SELECT * FROM automation_snmp_items WHERE id = ?', [$created]);
        $values = array_map(static fn(string $column): ?string => $stored[$column] === null ? null : (string) $stored[$column], ['id', 'snmp_id', ...SnmpOptionEntryChange::COLUMNS]);
        self::assertNotSame(hash('sha256', json_encode($values, JSON_THROW_ON_ERROR)), $entry->revision);
        self::assertSame(SnmpOptionEntry::revisionOf($stored, 'test-revision-key'), $entry->revision);
        self::assertNotSame(SnmpOptionEntry::revisionOf($stored, 'another-key'), $entry->revision);
        self::assertSame('[None]', $this->store->entries($set)->entries[0]->authProtocol);

        $v3 = ['snmp_version' => 3, 'snmp_auth_protocol' => 'SHA256', 'snmp_priv_protocol' => 'AES128'] + self::V2;
        $this->store->saveEntry(7, $set, $created, new SnmpOptionEntryChange($v3, false, ['snmp_community' => '', 'snmp_username' => 'ops', 'snmp_password' => 'authpass1', 'snmp_priv_passphrase' => 'privpass1']), $entry->revision);
        $entry = $this->store->findEntry($set, $created);
        // Keeping credentials changes only the settings.
        $this->store->saveEntry(7, $set, $created, new SnmpOptionEntryChange(['snmp_port' => 1161] + $v3, true, self::NO_CREDENTIALS), $entry->revision);
        $row = $this->database->fetchAssociative('SELECT snmp_port, snmp_username, snmp_password, snmp_priv_passphrase, snmp_auth_protocol FROM automation_snmp_items WHERE id = ?', [$created]);
        self::assertSame(['1161', 'ops', 'authpass1', 'privpass1', 'SHA256'], array_map('strval', array_values($row)));

        // Leaving SNMPv3 clears its credentials and protocol settings; a kept community is required.
        $entry = $this->store->findEntry($set, $created);
        try {
            $this->store->saveEntry(7, $set, $created, new SnmpOptionEntryChange(self::V2, true, self::NO_CREDENTIALS), $entry->revision);
            self::fail('A version 2 entry without a community was stored.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('SNMP versions 1 and 2 require a community.', $error->getMessage());
        }
        $this->store->saveEntry(7, $set, $created, new SnmpOptionEntryChange(self::V2, false, ['snmp_community' => 'again'] + self::NO_CREDENTIALS), $entry->revision);
        $row = $this->database->fetchAssociative('SELECT snmp_community, snmp_username, snmp_password, snmp_priv_passphrase, snmp_auth_protocol, snmp_priv_protocol FROM automation_snmp_items WHERE id = ?', [$created]);
        self::assertSame(['again', '', '', '', '[None]', '[None]'], array_values($row));
        self::assertSame('automation.snmp_option.entry_edit', $this->audit[array_key_last($this->audit)]->action);
        // Audit events name the change but never carry credential values.
        $recorded = serialize($this->audit);
        foreach (['secret-community', 'authpass1', 'privpass1', 'again'] as $secret) {
            self::assertStringNotContainsString($secret, $recorded);
        }
    }

    public function testEntriesAreScopedToTheirSetAndRefuseStaleOrNewKeptCredentials(): void
    {
        $first = $this->store->saveSet(7, null, 'First', null);
        $second = $this->store->saveSet(7, null, 'Second', null);
        $entry = $this->store->saveEntry(7, $first, null, new SnmpOptionEntryChange(self::V2, false, ['snmp_community' => 'c'] + self::NO_CREDENTIALS), null);
        $revision = $this->store->findEntry($first, $entry)->revision;
        self::assertNull($this->store->findEntry($second, $entry));
        foreach ([
            'edit through another set' => fn() => $this->store->saveEntry(7, $second, $entry, new SnmpOptionEntryChange(self::V2, true, self::NO_CREDENTIALS), $revision),
            'delete through another set' => fn() => $this->store->deleteEntry(7, $second, $entry, $revision),
            'missing set' => fn() => $this->store->saveEntry(7, 99, null, new SnmpOptionEntryChange(self::V2, false, ['snmp_community' => 'c'] + self::NO_CREDENTIALS), null),
            'stale edit' => fn() => $this->store->saveEntry(7, $first, $entry, new SnmpOptionEntryChange(self::V2, true, self::NO_CREDENTIALS), 'stale'),
            'kept credentials for a new entry' => fn() => $this->store->saveEntry(7, $first, null, new SnmpOptionEntryChange(self::V2, true, self::NO_CREDENTIALS), null),
        ] as $case => $write) {
            try {
                $write();
                self::fail('Accepted ' . $case);
            } catch (\InvalidArgumentException) {
            }
        }
        // Another administrator replacing the credentials makes an open editor stale.
        $this->database->update('automation_snmp_items', ['snmp_community' => 'rotated'], ['id' => $entry]);
        try {
            $this->store->deleteEntry(7, $first, $entry, $revision);
            self::fail('A stale delete was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('changed since', $error->getMessage());
        }
        $this->store->deleteEntry(7, $first, $entry, $this->store->findEntry($first, $entry)->revision);
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM automation_snmp_items'));
    }

    public function testMovesSwapNeighboursRenumberDenselyAndRefuseBoundariesAndStaleOrders(): void
    {
        $set = $this->store->saveSet(7, null, 'Core', null);
        $other = $this->store->saveSet(7, null, 'Other', null);
        foreach ([[10, $set, 7], [11, $set, 0], [12, $set, 0], [13, $set, 3], [14, $other, 1]] as [$id, $snmpId, $sequence]) {
            $this->database->insert('automation_snmp_items', ['id' => $id, 'snmp_id' => $snmpId, 'sequence' => $sequence, 'snmp_community' => 'c']);
        }
        $entries = $this->store->entries($set);
        self::assertSame([11, 12, 13, 10], array_map(static fn($entry): int => $entry->id, $entries->entries));
        self::assertSame([11, 10], [$entries->first(), $entries->last()]);
        $this->store->moveEntry(7, $set, 12, 1, $entries->order);
        self::assertSame([[11, 1], [13, 2], [12, 3], [10, 4]], $this->sequences($set));
        self::assertSame([[14, 1]], $this->sequences($other));
        foreach ([[11, -1], [10, 1], [14, 1], [12, 2]] as [$id, $offset]) {
            try {
                $this->store->moveEntry(7, $set, $id, $offset, $this->store->entries($set)->order);
                self::fail('Accepted a move past the boundary or from another set.');
            } catch (\InvalidArgumentException) {
            }
        }
        try {
            $this->store->moveEntry(7, $set, 13, -1, $entries->order);
            self::fail('A stale order was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('order changed', $error->getMessage());
        }
        self::assertSame([[11, 1], [13, 2], [12, 3], [10, 4]], $this->sequences($set));
    }

    public function testRevocationInsideTheTransactionWritesNothingAndIsAudited(): void
    {
        $this->revoked = true;
        try {
            $this->store->saveSet(7, null, 'Core', null);
            self::fail('A revoked actor wrote.');
        } catch (AutomationTemplateAccessDenied) {
        }
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM automation_snmp'));
        self::assertSame(AuditEvent::DENIED, $this->audit[0]->outcome);
    }

    public function testDefaultsComeFromTheLegacySettingsAndFallBackWhenUnsupported(): void
    {
        self::assertSame(['snmp_version' => 2, 'snmp_port' => 161, 'snmp_timeout' => 500, 'snmp_retries' => 3, 'max_oids' => 10, 'bulk_walk_size' => 0,
            'snmp_auth_protocol' => '[None]', 'snmp_priv_protocol' => '[None]', 'snmp_context' => '', 'snmp_engine_id' => ''], $this->store->entryDefaults());
        foreach (['snmp_version' => '3', 'snmp_port' => '1161', 'snmp_timeout' => '900', 'snmp_retries' => '300', 'max_get_size' => '7', 'snmp_auth_protocol' => 'SHA', 'snmp_priv_protocol' => 'AES', 'snmp_community' => 'never-a-default'] as $name => $value) {
            $this->database->insert('settings', ['name' => $name, 'value' => $value]);
        }
        $defaults = $this->store->entryDefaults();
        self::assertSame([3, 1161, 900, 3, 10, 'SHA', 'AES'], [$defaults['snmp_version'], $defaults['snmp_port'], $defaults['snmp_timeout'], $defaults['snmp_retries'], $defaults['max_oids'], $defaults['snmp_auth_protocol'], $defaults['snmp_priv_protocol']]);
        self::assertStringNotContainsString('never-a-default', serialize($defaults));
    }

    /** @return list<array{int, int}> */
    private function sequences(int $set): array
    {
        return array_map(static fn(array $row): array => [(int) $row['id'], (int) $row['sequence']], $this->database->fetchAllAssociative('SELECT id, sequence FROM automation_snmp_items WHERE snmp_id = ? ORDER BY sequence, id', [$set]));
    }
}
