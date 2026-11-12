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
use Kadupul\Automation\Application\Port\DiscoveryNetworkStore;
use Kadupul\Automation\Domain\DiscoveryNetwork;
use Kadupul\Automation\Domain\DiscoveryNetworkChange;
use Kadupul\Automation\Domain\DiscoveryNetworkRejected;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Infrastructure\Persistence\AuditedPrimaryWrite;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * automation_networks. poller_automation.php reads the General settings when
 * it schedules and runs a discovery, and writes the run state columns, which
 * this store never changes.
 */
#[AsAlias(DiscoveryNetworkStore::class)]
final readonly class DbalDiscoveryNetworkStore implements DiscoveryNetworkStore
{
    // Every table a write changes or locks, with the permission tables the actor recheck reads.
    private const array WRITE_TABLES = ['automation_networks', 'poller', 'sites', 'automation_snmp', 'user_auth', 'user_auth_realm', 'user_auth_group',
        'user_auth_group_realm', 'user_auth_group_members', 'settings'];
    private const string STALE = 'The discovery network changed since you opened this form. Reload before saving.';

    private AuditedPrimaryWrite $writes;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
        AutomationTemplateAccess $access,
        AuditTrail $audit,
        LegacyConfiguration $configuration,
    ) {
        // Discovery networks share the automation realm, so the Device Rules recheck applies unchanged.
        $this->writes = new AuditedPrimaryWrite(
            $database,
            $audit,
            $configuration,
            $access->assertCurrent(...),
            self::WRITE_TABLES,
            'Discovery Network',
            'Discovery Networks',
            'automation.discovery_network',
            'automation_network'
        );
    }

    public function defaults(): array
    {
        $settings = [];
        foreach ($this->database->fetchAllAssociative("SELECT name, value FROM settings WHERE name IN ('default_poller', 'default_site', 'ping_method', 'ping_port', 'ping_timeout', 'ping_retries')") as $row) {
            $settings[self::text($row['name'])] = self::text($row['value']);
        }
        // Each setting falls back to its global_settings.php default when unset or out of range.
        $integer = static function (string $setting, string $field, int $fallback) use ($settings): int {
            $value = filter_var($settings[$setting] ?? null, FILTER_VALIDATE_INT);
            [$min, $max] = DiscoveryNetworkChange::INTEGERS[$field];
            return is_int($value) && $value >= $min && $value <= $max ? $value : $fallback;
        };
        $method = filter_var($settings['ping_method'] ?? null, FILTER_VALIDATE_INT);
        return array_fill_keys(DiscoveryNetworkChange::FLAGS, false) + array_fill_keys(array_keys(DiscoveryNetworkChange::LISTS), []) + [
            'name' => '',
            'poller_id' => $integer('default_poller', 'poller_id', 1),
            'site_id' => $integer('default_site', 'site_id', 1),
            'subnet_range' => '',
            'dns_servers' => '',
            'notification_email' => '',
            'notification_fromname' => '',
            'notification_fromemail' => '',
            // Legacy left the first SNMP Option Set selected.
            'snmp_id' => array_key_first($this->choices()['snmp']) ?? 0,
            'ping_method' => in_array($method, DiscoveryNetworkChange::CHOICES['ping_method'], true) ? $method : 2,
            'ping_port' => $integer('ping_port', 'ping_port', 23),
            'ping_timeout' => $integer('ping_timeout', 'ping_timeout', 400),
            'ping_retries' => $integer('ping_retries', 'ping_retries', 1),
            'sched_type' => 1,
            'threads' => 1,
            'run_limit' => 1200,
            'start_at' => date('Y-m-d H:i:s'),
            'recur_every' => 1,
        ];
    }

    public function choices(): array
    {
        $options = function (string $table): array {
            $choices = [];
            foreach ($this->database->fetchAllAssociative('SELECT id, name FROM ' . $table . ' ORDER BY name, id') as $row) {
                $choices[self::int($row['id'], 0)] = self::text($row['name']);
            }
            return $choices;
        };
        return ['pollers' => $options('poller'), 'sites' => $options('sites'), 'snmp' => $options('automation_snmp')];
    }

    public function find(int $id): ?DiscoveryNetwork
    {
        $row = $this->database->fetchAssociative('SELECT ' . implode(', ', DiscoveryNetwork::REVISION_COLUMNS) . ', total_ips FROM automation_networks WHERE id = ?', [$id]);
        if ($row === false) {
            return null;
        }
        $settings = [];
        foreach (DiscoveryNetworkChange::FLAGS as $flag) {
            $settings[$flag] = self::text($row[$flag]) === 'on';
        }
        foreach (array_keys(DiscoveryNetworkChange::TEXT) as $name) {
            $settings[$name] = self::text($row[$name]);
        }
        $settings['subnet_range'] = self::text($row['subnet_range']);
        // The legacy form showed the current time when no start was stored; saving it then reschedules, as it did.
        if ($settings['start_at'] === '') {
            $settings['start_at'] = date('Y-m-d H:i:s');
        }
        foreach ([...array_keys(DiscoveryNetworkChange::INTEGERS), ...array_keys(DiscoveryNetworkChange::CHOICES)] as $name) {
            // NULL snmp_id or site_id reads as 0, which the form shows as unselected or None.
            $settings[$name] = self::int($row[$name], 0);
        }
        foreach (DiscoveryNetworkChange::LISTS as $name => $allowed) {
            // Values the form cannot offer are dropped rather than failing the page.
            $settings[$name] = array_values(array_intersect($allowed, array_map('intval', array_filter(explode(',', self::text($row[$name])), 'is_numeric'))));
        }
        return new DiscoveryNetwork(self::int($row['id'], 0), self::text($row['name']), self::int($row['total_ips'], 0), $settings, DiscoveryNetwork::revisionOf($row));
    }

    public function save(int $actorId, ?int $id, DiscoveryNetworkChange $change, ?string $revision): int
    {
        return $this->writes->run($actorId, $id === null ? 'create' : 'edit', 'automation_network', function () use ($id, $change, $revision): int {
            $this->assertReferences($change);
            if ($id === null) {
                $this->database->insert('automation_networks', $change->columns(null));
                $created = (int) $this->database->lastInsertId();
                if ($created < 1) {
                    throw new \RuntimeException('Discovery network creation was not confirmed.');
                }
                return $created;
            }
            $lock = $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' FOR UPDATE' : '';
            $row = $this->database->fetchAssociative('SELECT ' . implode(', ', DiscoveryNetwork::REVISION_COLUMNS) . ' FROM automation_networks WHERE id = ?' . $lock, [$id]);
            if ($row === false) {
                throw new \InvalidArgumentException('Discovery network not found.');
            }
            if ($revision === null || !hash_equals(DiscoveryNetwork::revisionOf($row), $revision)) {
                throw new \InvalidArgumentException(self::STALE);
            }
            $this->database->update('automation_networks', $change->columns($row), ['id' => $id]);
            return $id;
        });
    }

    /** Shared locks keep a referenced collector, site or option set from being deleted before commit. */
    private function assertReferences(DiscoveryNetworkChange $change): void
    {
        $lock = $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' LOCK IN SHARE MODE' : '';
        $references = [['poller', 'poller_id', 'Choose an existing Data Collector.'], ['automation_snmp', 'snmp_id', 'Choose an existing SNMP Option Set.']];
        // Site 0 is "None".
        if ($change->settings['site_id'] !== 0) {
            $references[] = ['sites', 'site_id', 'Choose an existing Site.'];
        }
        foreach ($references as [$table, $field, $message]) {
            if ($this->database->fetchOne('SELECT id FROM ' . $table . ' WHERE id = ?' . $lock, [$change->settings[$field]]) === false) {
                throw new DiscoveryNetworkRejected($message, [], $field);
            }
        }
    }

    private static function int(mixed $value, int $fallback): int
    {
        return is_numeric($value) ? (int) $value : $fallback;
    }

    /** NULL columns read as empty text, as the legacy form showed them. */
    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : '');
    }
}
