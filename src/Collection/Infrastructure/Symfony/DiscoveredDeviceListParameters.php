<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Infrastructure\Symfony;

use Kadupul\Collection\Domain\DiscoveredDeviceCriteria;

final class DiscoveredDeviceListParameters
{
    /** @param array<string, mixed> $query @return array<string, string> */
    public static function formData(array $query): array
    {
        if (array_diff(array_keys($query), ['q', 'network', 'status', 'snmp', 'os', 'page', 'size', 'sort', 'direction', 'discovery_filter']) !== []) {
            throw new \InvalidArgumentException('Invalid automation device filters.');
        }
        $data = $query['discovery_filter'] ?? [];
        if (!is_array($data) || array_diff(array_keys($data), ['q', 'network', 'status', 'snmp', 'os', 'size', 'sort', 'direction']) !== []) {
            throw new \InvalidArgumentException('Invalid automation device filters.');
        }
        $source = $data === [] ? $query : $data;
        $result = [];
        foreach (['q', 'network', 'status', 'snmp', 'os', 'size', 'sort', 'direction'] as $field) {
            if (isset($source[$field]) && !is_string($source[$field])) {
                throw new \InvalidArgumentException('Invalid automation device filters.');
            }
            if (isset($source[$field])) {
                $result[$field] = $source[$field];
            }
        }
        return $result;
    }

    /** @param array<string, mixed> $query @param array<string, mixed> $form */
    public static function parse(array $query, array $form): DiscoveredDeviceCriteria
    {
        $page = $query['page'] ?? '1';
        if (!is_string($page) || !preg_match('/\A[1-9][0-9]{0,5}\z/D', $page)) {
            throw new \InvalidArgumentException('Invalid automation device filters.');
        }
        $network = (string) ($form['network'] ?? '-1');
        if ($network !== '-1' && (!preg_match('/\A[1-9][0-9]{0,9}\z/D', $network))) {
            throw new \InvalidArgumentException('Invalid automation device filters.');
        }
        return new DiscoveredDeviceCriteria(
            (string) ($form['q'] ?? ''),
            $network === '-1' ? null : (int) $network,
            (string) ($form['status'] ?? 'all'),
            (string) ($form['snmp'] ?? 'all'),
            (string) ($form['os'] ?? ''),
            (int) $page,
            (int) ($form['size'] ?? 25),
            (string) ($form['sort'] ?? 'hostname'),
            (string) ($form['direction'] ?? 'asc')
        );
    }
}
