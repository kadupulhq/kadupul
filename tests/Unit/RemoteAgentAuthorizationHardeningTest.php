<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

$remoteAgentSource = file_get_contents(__DIR__ . '/../../remote_agent.php');

test('remote agent authorization delegates to verified poller identity resolver', function () use ($remoteAgentSource) {
    expect($remoteAgentSource)->toContain('remote_agent_resolve_poller(');
});

test('remote agent authorization only loads enabled pollers', function () use ($remoteAgentSource) {
    expect($remoteAgentSource)->toContain('SELECT * FROM poller WHERE disabled = ""');
});

test('remote agent authorization no longer suppresses dns_get_record errors', function () use ($remoteAgentSource) {
    expect($remoteAgentSource)->not->toContain('@dns_get_record(');
    expect($remoteAgentSource)->toContain('dns_get_record($name, DNS_A | DNS_AAAA)');
});

test('remote agent authorization does not trust HTTP Host header', function () use ($remoteAgentSource) {
    $start = strpos($remoteAgentSource, 'function remote_client_authorized()');
    expect($start)->not->toBeFalse();

    $body = substr($remoteAgentSource, $start, 2200);
    expect($body)->not->toContain('HTTP_HOST');
    expect($body)->not->toContain('SERVER_NAME');
});

test('remote agent authorization caches dns authorization decisions', function () use ($remoteAgentSource) {
    expect($remoteAgentSource)->toContain("'remote_agent_auth_cache_get'");
    expect($remoteAgentSource)->toContain("'remote_agent_auth_cache_set'");
    expect($remoteAgentSource)->toContain('is_int($result) && $result >= 0');
});
