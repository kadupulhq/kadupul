<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Resolve one enabled poller, including forward-verified hostname identities. */
function remote_agent_resolve_poller(string $address, array $pollers, callable $reverse, callable $forward, callable $fetch, callable $store): int
{
    if (!filter_var($address, FILTER_VALIDATE_IP) || count($pollers) <= 1) {
        return 0;
    }
    $direct = array();
    $names = array();
    foreach ($pollers as $poller) {
        $hostname = trim($poller['hostname']);
        $id = (int) $poller['id'];
        if ($hostname === '' || $id <= 0 || !empty($poller['disabled'])) {
            continue;
        }
        if ($hostname === $address) {
            $direct[] = $id;
        } elseif (!filter_var($hostname, FILTER_VALIDATE_IP)) {
            $names[strtolower(rtrim($hostname, '.'))][] = $id;
        }
    }
    if (count($direct) > 0) {
        return count($direct) === 1 ? $direct[0] : 0;
    }
    if (empty($names)) {
        return 0;
    }
    ksort($names);
    foreach ($names as &$ids) {
        sort($ids);
    }
    unset($ids);
    // Bind cache entries to the complete current hostname-to-ID mapping.
    $key = 'remote_agent_auth_v3:' . hash('sha256', $address . '|' . json_encode($names, JSON_THROW_ON_ERROR));
    $cached = $fetch($key);
    if ($cached === 0) {
        return 0;
    }
    if (is_int($cached) && $cached > 0) {
        foreach ($names as $ids) {
            if (count($ids) === 1 && $ids[0] === $cached) {
                return $cached;
            }
        }
    }
    $name = $reverse($address);
    $normalized = is_string($name) ? strtolower(rtrim($name, '.')) : '';
    $identity = 0;
    if (isset($names[$normalized]) && count($names[$normalized]) === 1) {
        $records = $forward($name);
        foreach (is_array($records) ? $records : array() as $record) {
            if (($record['ip'] ?? $record['ipv6'] ?? '') === $address) {
                $identity = $names[$normalized][0];
                break;
            }
        }
    }
    $store($key, $identity);

    return $identity;
}
