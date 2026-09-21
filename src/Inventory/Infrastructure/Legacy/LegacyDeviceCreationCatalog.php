<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Application\Port\DeviceCreationCatalog;
use Kadupul\Inventory\Application\Query\DeviceCreationChoices;
use Kadupul\Inventory\Domain\NewDevice;
use Kadupul\Platform\Contract\DatabaseConnection;

final readonly class LegacyDeviceCreationCatalog implements DeviceCreationCatalog
{
    public function __construct(private DatabaseConnection $database) {}

    public function choices(): DeviceCreationChoices
    {
        $db = $this->database->get();
        $defaults = NewDevice::DEFAULTS;
        $mapping = ['default_template' => 'host_template_id', 'default_site' => 'site_id', 'default_poller' => 'poller_id', 'max_get_size' => 'max_oids'];
        foreach (['snmp_version', 'snmp_username', 'snmp_auth_protocol', 'snmp_priv_protocol', 'snmp_port', 'snmp_timeout', 'device_threads', 'availability_method', 'ping_method', 'ping_port', 'ping_timeout', 'ping_retries'] as $name) {
            $mapping[$name] = $name;
        }
        // Do not select community strings or stored passphrases into the HTTP process.
        $query = $db->prepare('SELECT name, value FROM settings WHERE name IN (' . implode(',', array_fill(0, count($mapping), '?')) . ')');
        $query->execute(array_keys($mapping));
        foreach ($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($row['value'] !== '') {
                $defaults[$mapping[$row['name']]] = (string) $row['value'];
            }
        }
        $templates = $db->query('SELECT id, name FROM host_template WHERE id > 0 ORDER BY name, id')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $sites = $db->query('SELECT id, name FROM sites WHERE id > 0 ORDER BY name, id')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $pollers = $db->query("SELECT id, name FROM poller WHERE id > 0 AND disabled = '' ORDER BY name, id")->fetchAll(\PDO::FETCH_KEY_PAIR);
        foreach (['host_template_id' => $templates, 'site_id' => $sites, 'poller_id' => $pollers] as $field => $choices) {
            $id = (int) $defaults[$field];
            $defaults[$field] = isset($choices[$id]) ? $id : ($field === 'poller_id' ? (int) (array_key_first($pollers) ?? 1) : 0);
        }

        return new DeviceCreationChoices($defaults, $templates, $sites, $pollers);
    }
}
