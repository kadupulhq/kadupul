<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Application\Port\NotificationReceiverAccess;
use Kadupul\Platform\Application\Port\RrdCheckAccess;
use Kadupul\Platform\Application\Query\NotificationReceiverAccessDenied;
use Kadupul\Platform\Application\Query\RrdCheckAccessDenied;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Domain\NotificationReceiver;
use Kadupul\Platform\Domain\NotificationReceiverChange;
use Kadupul\Platform\Infrastructure\Persistence\DbalNotificationReceiverAccess;
use Kadupul\Platform\Infrastructure\Persistence\DbalNotificationReceiverStore;
use PHPUnit\Framework\TestCase;

final class NotificationReceiverStoreTest extends TestCase
{
    private const array V2 = ['description' => 'NOC', 'hostname' => 'trap.example.net', 'disabled' => false, 'max_log_size' => 31, 'snmp_version' => 2,
        'snmp_auth_protocol' => '[None]', 'snmp_priv_protocol' => '[None]', 'snmp_engine_id' => '', 'snmp_port' => 162, 'snmp_message_type' => 1, 'notes' => ''];
    private const array NO_CREDENTIALS = ['snmp_community' => '', 'snmp_username' => '', 'snmp_password' => '', 'snmp_priv_passphrase' => ''];

    private Connection $database;
    private DbalNotificationReceiverStore $store;
    private bool $revoked = false;
    /** @var list<bool> */
    private array $checkedInTransaction = [];
    /** @var list<AuditEvent> */
    private array $audit = [];

    protected function setUp(): void
    {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        // The cacti.sql columns, with their nullability and defaults.
        foreach ([
            "CREATE TABLE snmpagent_managers (id INTEGER PRIMARY KEY AUTOINCREMENT, hostname TEXT NOT NULL, description TEXT NOT NULL, disabled TEXT DEFAULT NULL,
                max_log_size INT NOT NULL, snmp_version INT NOT NULL DEFAULT 1, snmp_community TEXT NOT NULL DEFAULT '', snmp_username TEXT NOT NULL,
                snmp_password TEXT NOT NULL, snmp_auth_protocol TEXT NOT NULL, snmp_priv_passphrase TEXT NOT NULL, snmp_priv_protocol TEXT NOT NULL,
                snmp_engine_id TEXT DEFAULT NULL, snmp_port INT NOT NULL DEFAULT 161, snmp_message_type INT NOT NULL, notes TEXT)",
            'CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)',
        ] as $statement) {
            $this->database->executeStatement($statement);
        }
        $access = $this->createMock(NotificationReceiverAccess::class);
        $access->method('assertCurrent')->willReturnCallback(function (): void {
            $this->checkedInTransaction[] = $this->database->isTransactionActive();
            if ($this->revoked) {
                throw new NotificationReceiverAccessDenied(false);
            }
        });
        $audit = $this->createMock(AuditTrail::class);
        $audit->method('record')->willReturnCallback(function (AuditEvent $event): void {
            $this->audit[] = $event;
        });
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);
        $this->store = new DbalNotificationReceiverStore($this->database, $access, $audit, $configuration, 'test-revision-key');
    }

    public function testReceiversAreCreatedWithTheLegacyColumnValuesAndNeverReturnCredentials(): void
    {
        $id = $this->store->save(7, null, new NotificationReceiverChange(
            ['disabled' => true, 'notes' => "a\nb", 'description' => '  NOC <b>  '] + self::V2,
            false,
            ['snmp_community' => 'secret-community'] + self::NO_CREDENTIALS
        ), null);
        $row = $this->database->fetchAssociative('SELECT * FROM snmpagent_managers WHERE id = ?', [$id]);
        // Legacy trimmed description and hostname, stored 'on' for disabled, and empty SNMPv3 fields for other versions.
        self::assertSame(['NOC <b>', 'on', 'secret-community', '', '', '', '', '', '', "a\nb", '162', '2', '1', '31'], [
            $row['description'], $row['disabled'], $row['snmp_community'], $row['snmp_username'], $row['snmp_password'], $row['snmp_auth_protocol'],
            $row['snmp_priv_passphrase'], $row['snmp_priv_protocol'], $row['snmp_engine_id'], $row['notes'],
            (string) $row['snmp_port'], (string) $row['snmp_version'], (string) $row['snmp_message_type'], (string) $row['max_log_size'],
        ]);
        $receiver = $this->store->find($id);
        self::assertNotNull($receiver);
        self::assertSame(['NOC <b>', true, '[None]', '[None]'], [$receiver->description, $receiver->disabled, $receiver->authProtocol, $receiver->privProtocol]);
        self::assertStringNotContainsString('secret-community', serialize($receiver));
        self::assertNull($this->store->find($id + 1));
        self::assertSame('utilities.notification_receiver.create', $this->audit[0]->action);
        self::assertSame([true], $this->checkedInTransaction);
    }

    public function testRevisionsAreKeyedAndCoverCredentials(): void
    {
        $id = $this->store->save(7, null, new NotificationReceiverChange(self::V2, false, ['snmp_community' => 'public'] + self::NO_CREDENTIALS), null);
        $stored = $this->database->fetchAssociative('SELECT * FROM snmpagent_managers WHERE id = ?', [$id]);
        $revision = $this->store->find($id)->revision;
        // The revision reaches the page, so it must not be a digest anyone can recompute from guessed credentials.
        $values = array_map(static fn(string $column): ?string => $stored[$column] === null ? null : (string) $stored[$column], ['id', ...NotificationReceiverChange::COLUMNS]);
        self::assertNotSame(hash('sha256', json_encode($values, JSON_THROW_ON_ERROR)), $revision);
        self::assertSame(NotificationReceiver::revisionOf($stored, 'test-revision-key'), $revision);
        self::assertNotSame(NotificationReceiver::revisionOf($stored, 'another-key'), $revision);
        // Another administrator replacing only the community makes an open editor stale.
        $this->database->update('snmpagent_managers', ['snmp_community' => 'private'], ['id' => $id]);
        foreach ([$revision, null, ''] as $stale) {
            try {
                $this->store->save(7, $id, new NotificationReceiverChange(self::V2, true, self::NO_CREDENTIALS), $stale);
                self::fail('A stale notification receiver edit was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('changed since', $error->getMessage());
            }
        }
        try {
            $this->store->save(7, $id + 1, new NotificationReceiverChange(self::V2, true, self::NO_CREDENTIALS), $revision);
            self::fail('A missing receiver was edited.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Notification receiver not found.', $error->getMessage());
        }
        $this->expectException(\LogicException::class);
        new DbalNotificationReceiverStore($this->database, $this->createMock(NotificationReceiverAccess::class), $this->createMock(AuditTrail::class), $this->createMock(LegacyConfiguration::class), '');
    }

    public function testCredentialsAreKeptOrReplacedAndLeavingVersionThreeClearsThem(): void
    {
        $v3 = ['snmp_version' => 3, 'snmp_auth_protocol' => 'SHA256', 'snmp_priv_protocol' => 'AES128', 'snmp_engine_id' => '0x80001f88'] + self::V2;
        $secrets = ['snmp_community' => '', 'snmp_username' => 'ops', 'snmp_password' => 'authpass1', 'snmp_priv_passphrase' => 'privpass1'];
        $id = $this->store->save(7, null, new NotificationReceiverChange($v3, false, $secrets), null);
        // Keeping credentials changes only the settings.
        $this->store->save(7, $id, new NotificationReceiverChange(['snmp_port' => 1162] + $v3, true, self::NO_CREDENTIALS), $this->store->find($id)->revision);
        $columns = 'SELECT snmp_port, snmp_username, snmp_password, snmp_priv_passphrase, snmp_auth_protocol, snmp_priv_protocol, snmp_engine_id FROM snmpagent_managers WHERE id = ?';
        self::assertSame(['1162', 'ops', 'authpass1', 'privpass1', 'SHA256', 'AES128', '0x80001f88'], array_map('strval', array_values($this->database->fetchAssociative($columns, [$id]))));
        // A kept secret still has to match the submitted protocol.
        try {
            $this->store->save(7, $id, new NotificationReceiverChange(['snmp_priv_protocol' => '[None]'] + $v3, true, self::NO_CREDENTIALS), $this->store->find($id)->revision);
            self::fail('A kept privacy passphrase without a protocol was stored.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('together with its passphrase', $error->getMessage());
        }
        // Leaving SNMPv3 clears its credentials and settings even when the rest are kept.
        $this->store->save(7, $id, new NotificationReceiverChange(self::V2, true, self::NO_CREDENTIALS), $this->store->find($id)->revision);
        self::assertSame(['162', '', '', '', '', '', ''], array_map('strval', array_values($this->database->fetchAssociative($columns, [$id]))));
        // Audit events name the change but never carry credential values.
        $recorded = serialize($this->audit);
        foreach (['authpass1', 'privpass1'] as $secret) {
            self::assertStringNotContainsString($secret, $recorded);
        }
        self::assertSame('utilities.notification_receiver.edit', $this->audit[array_key_last($this->audit)]->action);
    }

    public function testLegacyControlCharactersInKeptCredentialsMustBeReplaced(): void
    {
        // Legacy before the stripping fix could store these; they reach the snmptrap command line.
        $this->database->insert('snmpagent_managers', ['hostname' => 'h', 'description' => 'd', 'max_log_size' => 31, 'snmp_version' => 2, 'snmp_community' => "pub\nlic",
            'snmp_username' => '', 'snmp_password' => '', 'snmp_auth_protocol' => '', 'snmp_priv_passphrase' => '', 'snmp_priv_protocol' => '', 'snmp_message_type' => 1]);
        $id = (int) $this->database->lastInsertId();
        $receiver = $this->store->find($id);
        // NULL legacy columns read as empty values.
        self::assertSame(['', '', false], [$receiver->engineId, $receiver->notes, $receiver->disabled]);
        try {
            $this->store->save(7, $id, new NotificationReceiverChange(self::V2, true, self::NO_CREDENTIALS), $receiver->revision);
            self::fail('A kept control character was stored.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('control characters', $error->getMessage());
        }
        $this->store->save(7, $id, new NotificationReceiverChange(self::V2, false, ['snmp_community' => 'public'] + self::NO_CREDENTIALS), $receiver->revision);
        self::assertSame('public', $this->database->fetchOne('SELECT snmp_community FROM snmpagent_managers WHERE id = ?', [$id]));
    }

    public function testChangesRejectUnsupportedValuesBeforeAnyWrite(): void
    {
        $credentials = ['snmp_username' => 'ops', 'snmp_password' => 'authpass1', 'snmp_priv_passphrase' => '', 'snmp_community' => ''];
        $v3 = ['snmp_version' => 3, 'snmp_auth_protocol' => 'SHA', 'snmp_engine_id' => '0x80'] + self::V2;
        $cases = [
            [['hostname' => str_repeat('h', 101)] + self::V2, false, self::NO_CREDENTIALS],
            [['description' => str_repeat('é', 256)] + self::V2, false, self::NO_CREDENTIALS],
            [['hostname' => "\xff"] + self::V2, false, self::NO_CREDENTIALS],
            [['notes' => "a\0b"] + self::V2, false, self::NO_CREDENTIALS],
            [['max_log_size' => 0] + self::V2, false, self::NO_CREDENTIALS],
            [['snmp_port' => 0] + self::V2, false, self::NO_CREDENTIALS],
            [['snmp_message_type' => 3] + self::V2, false, self::NO_CREDENTIALS],
            [['snmp_auth_protocol' => 'SHA1'] + self::V2, false, self::NO_CREDENTIALS],
            // snmptrap receives the protocols as arguments, so only exact names pass.
            [['snmp_auth_protocol' => 'SHA256 -X x'] + $v3, false, $credentials],
            [['snmp_auth_protocol' => 'xMD5'] + $v3, false, $credentials],
            [['snmp_priv_protocol' => 'DES; x', 'snmp_priv_passphrase' => 'privpass1'] + $v3, false, ['snmp_priv_passphrase' => 'privpass1'] + $credentials],
            [array_diff_key(self::V2, ['notes' => true]), false, self::NO_CREDENTIALS],
            [['extra' => 1] + self::V2, false, self::NO_CREDENTIALS],
            [self::V2, false, ['snmp_community' => str_repeat('c', 101)] + self::NO_CREDENTIALS],
            [self::V2, true, ['snmp_community' => 'typed'] + self::NO_CREDENTIALS],
            [['snmp_engine_id' => ''] + $v3, false, $credentials],
            [$v3, false, ['snmp_username' => ''] + $credentials],
            [$v3, false, ['snmp_password' => 'short'] + $credentials],
            [['snmp_priv_protocol' => 'AES'] + $v3, false, $credentials],
        ];
        foreach ($cases as $index => [$settings, $keep, $secrets]) {
            try {
                new NotificationReceiverChange($settings, $keep, $secrets);
                self::fail('Accepted invalid case ' . $index . '.');
            } catch (\InvalidArgumentException) {
            }
        }
        // Keeping credentials is meaningless for a receiver that has none.
        try {
            $this->store->save(7, null, new NotificationReceiverChange(self::V2, true, self::NO_CREDENTIALS), null);
            self::fail('A new receiver kept credentials.');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM snmpagent_managers'));
    }

    public function testRevocationInsideTheTransactionWritesNothingAndIsAudited(): void
    {
        $this->revoked = true;
        try {
            $this->store->save(7, null, new NotificationReceiverChange(self::V2, false, self::NO_CREDENTIALS), null);
            self::fail('A revoked actor wrote.');
        } catch (NotificationReceiverAccessDenied) {
        }
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM snmpagent_managers'));
        self::assertSame(AuditEvent::DENIED, $this->audit[0]->outcome);
    }

    public function testAccessDelegatesToTheUtilitiesPolicyAndKeepsTheDenialKind(): void
    {
        $utilities = $this->createMock(RrdCheckAccess::class);
        $utilities->method('authorize')->willReturnOnConsecutiveCalls(new Actor(7, 'admin'), self::throwException(new RrdCheckAccessDenied(true)));
        $utilities->method('assertCurrent')->willThrowException(new RrdCheckAccessDenied(false));
        $access = new DbalNotificationReceiverAccess($utilities);
        self::assertSame(7, $access->authorize()->id);
        foreach ([[fn() => $access->authorize(), true], [fn() => $access->assertCurrent(7), false]] as [$call, $unauthenticated]) {
            try {
                $call();
                self::fail('Access was granted.');
            } catch (NotificationReceiverAccessDenied $error) {
                self::assertSame($unauthenticated, $error->unauthenticated);
            }
        }
    }

    public function testDefaultsComeFromTheLegacySettingsAndFallBackWhenUnsupported(): void
    {
        self::assertSame(['description' => '', 'hostname' => '', 'disabled' => false, 'max_log_size' => 31, 'snmp_version' => 2, 'snmp_auth_protocol' => '[None]',
            'snmp_priv_protocol' => '[None]', 'snmp_engine_id' => '', 'snmp_port' => 161, 'snmp_message_type' => 1, 'notes' => ''], $this->store->defaults());
        foreach (['snmp_version' => '3', 'snmp_port' => '1161', 'snmp_auth_protocol' => 'SHA', 'snmp_priv_protocol' => 'AES', 'snmp_community' => 'never-a-default'] as $name => $value) {
            $this->database->insert('settings', ['name' => $name, 'value' => $value]);
        }
        $defaults = $this->store->defaults();
        self::assertSame([3, 1161, 'SHA', 'AES'], [$defaults['snmp_version'], $defaults['snmp_port'], $defaults['snmp_auth_protocol'], $defaults['snmp_priv_protocol']]);
        self::assertStringNotContainsString('never-a-default', serialize($defaults));
        $this->database->executeStatement("UPDATE settings SET value = '0' WHERE name IN ('snmp_version', 'snmp_port')");
        self::assertSame([2, 161], [$this->store->defaults()['snmp_version'], $this->store->defaults()['snmp_port']]);
    }
}
