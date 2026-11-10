<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Application\Port\NotificationReceiverAccess;
use Kadupul\Platform\Application\Port\NotificationReceiverStore;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Domain\NotificationReceiver;
use Kadupul\Platform\Domain\NotificationReceiverChange;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * snmpagent_managers. lib/snmpagent.php reads the rows when it sends a
 * notification; remote collectors do not replicate the table.
 */
#[AsAlias(NotificationReceiverStore::class)]
final readonly class DbalNotificationReceiverStore implements NotificationReceiverStore
{
    // Every table a write changes or locks, with the permission tables the actor recheck reads.
    private const array WRITE_TABLES = ['snmpagent_managers', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_realm', 'user_auth_group_members', 'settings'];
    private const string STALE = 'The notification receiver changed since you opened this form. Reload before saving.';
    private const string COLUMNS = 'id, hostname, description, disabled, max_log_size, snmp_version, snmp_community, snmp_username, snmp_password,
        snmp_auth_protocol, snmp_priv_passphrase, snmp_priv_protocol, snmp_engine_id, snmp_port, snmp_message_type, notes';

    private AuditedPrimaryWrite $writes;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
        NotificationReceiverAccess $access,
        AuditTrail $audit,
        LegacyConfiguration $configuration,
        /** Keys the revisions shown to the browser; see NotificationReceiver::revisionOf(). */
        #[Autowire('%kernel.secret%')]
        #[\SensitiveParameter]
        private string $revisionKey,
    ) {
        if ($revisionKey === '') {
            throw new \LogicException('The notification receiver revision key is not configured.');
        }
        $this->writes = new AuditedPrimaryWrite(
            $database,
            $audit,
            $configuration,
            $access->assertCurrent(...),
            self::WRITE_TABLES,
            'SNMP Notification Receiver',
            'SNMP Notification Receivers',
            'utilities.notification_receiver',
            'notification_receiver'
        );
    }

    public function defaults(): array
    {
        $settings = [];
        foreach ($this->database->fetchAllAssociative("SELECT name, value FROM settings WHERE name IN ('snmp_version', 'snmp_port', 'snmp_auth_protocol', 'snmp_priv_protocol')") as $row) {
            $settings[self::text($row['name'])] = self::text($row['value']);
        }
        $version = filter_var($settings['snmp_version'] ?? null, FILTER_VALIDATE_INT);
        $port = filter_var($settings['snmp_port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $choice = static fn(string $name, array $allowed): string => in_array($settings[$name] ?? null, $allowed, true) ? $settings[$name] : '[None]';
        return [
            'description' => '',
            'hostname' => '',
            'disabled' => false,
            'max_log_size' => NotificationReceiverChange::LOG_SIZE_MAX,
            'snmp_version' => in_array($version, NotificationReceiverChange::VERSIONS, true) ? $version : 2,
            'snmp_auth_protocol' => $choice('snmp_auth_protocol', NotificationReceiverChange::AUTH_PROTOCOLS),
            'snmp_priv_protocol' => $choice('snmp_priv_protocol', NotificationReceiverChange::PRIVACY_PROTOCOLS),
            'snmp_engine_id' => '',
            'snmp_port' => is_int($port) ? $port : 161,
            'snmp_message_type' => 1,
            'notes' => '',
        ];
    }

    public function find(int $id): ?NotificationReceiver
    {
        $row = $this->database->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM snmpagent_managers WHERE id = ?', [$id]);
        return $row === false ? null : $this->receiver($row);
    }

    public function save(int $actorId, ?int $id, NotificationReceiverChange $change, ?string $revision): int
    {
        return $this->writes->run($actorId, $id === null ? 'create' : 'edit', 'notification_receiver', function () use ($id, $change, $revision): int {
            if ($id === null) {
                $this->database->insert('snmpagent_managers', $change->resolve([]));
                $created = (int) $this->database->lastInsertId();
                if ($created < 1) {
                    throw new \RuntimeException('Notification receiver creation was not confirmed.');
                }
                return $created;
            }
            $lock = $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' FOR UPDATE' : '';
            $row = $this->database->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM snmpagent_managers WHERE id = ?' . $lock, [$id]);
            if ($row === false) {
                throw new \InvalidArgumentException('Notification receiver not found.');
            }
            if ($revision === null || !hash_equals(NotificationReceiver::revisionOf($row, $this->revisionKey), $revision)) {
                throw new \InvalidArgumentException(self::STALE);
            }
            $this->database->update('snmpagent_managers', $change->resolve($row), ['id' => $id]);
            return $id;
        });
    }

    /** @param array<string, mixed> $row */
    private function receiver(array $row): NotificationReceiver
    {
        // Legacy saved empty protocols for SNMP versions 1 and 2.
        $protocol = static fn(mixed $value): string => in_array(self::text($value), ['', '0'], true) ? '[None]' : self::text($value);
        return new NotificationReceiver(
            self::int($row['id']),
            self::text($row['description']),
            self::text($row['hostname']),
            self::text($row['disabled']) === 'on',
            self::int($row['max_log_size']),
            self::int($row['snmp_version']),
            $protocol($row['snmp_auth_protocol']),
            $protocol($row['snmp_priv_protocol']),
            self::text($row['snmp_engine_id']),
            self::int($row['snmp_port']),
            self::int($row['snmp_message_type']),
            self::text($row['notes']),
            NotificationReceiver::revisionOf($row, $this->revisionKey),
        );
    }

    private static function int(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new \UnexpectedValueException('The notification receiver table returned an unexpected row.');
        }
        return (int) $value;
    }

    /** NULL columns read as empty text, as the legacy form showed them. */
    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : '');
    }
}
