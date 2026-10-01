<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Contract\AuditEvent;

require dirname(__DIR__, 2) . '/include/vendor/autoload.php';

\umask((int) $argv[2]);
$violations = [];
$nested = false;
if ($argv[3] === 'existing') {
    \file_put_contents($argv[1] . '/log/kadupul-audit.jsonl', "{}\n");
    \chmod($argv[1] . '/log/kadupul-audit.jsonl', 0600);
}
function chmod(string $path, int $permissions): bool
{
    $GLOBALS['violations'][] = 'production changed file permissions after creation';
    return \chmod($path, $permissions);
}
function inspectPrivate(string $path): void
{
    clearstatcache(true, $path);
    if ((\fileperms($path) & 07777) !== 0600 || \umask() !== (int) $GLOBALS['argv'][2]) {
        $GLOBALS['violations'][] = 'unsafe inode or process mask';
    }
}
function umask(?int $mask = null): int
{
    $GLOBALS['violations'][] = 'production changed or read process mask';
    return $mask === null ? \umask() : \umask($mask);
}
function tempnam(string $directory, string $prefix): string|false
{
    return $GLOBALS['argv'][3] === 'temp-failure' ? false : \tempnam($directory, $prefix);
}
function link(string $source, string $destination): bool
{
    inspectPrivate($source);
    if ($GLOBALS['argv'][3] === 'link-failure') {
        return false;
    }
    if ($GLOBALS['argv'][3] === 'concurrent' && !$GLOBALS['nested']) {
        $GLOBALS['nested'] = true;
        // A second writer wins publication while the first owns only its private inode.
        (new LegacyAuditTrail($GLOBALS['argv'][1]))->record(event());
    }
    return \link($source, $destination);
}
function fopen(string $path, string $mode)
{
    inspectPrivate($path);
    if ($mode !== 'r+b') {
        $GLOBALS['violations'][] = 'open can create an unprotected inode';
    }
    return \fopen($path, $mode);
}
function event(): AuditEvent
{
    return new AuditEvent(bin2hex(random_bytes(16)), 42, 'inventory.device.edit', 'device', '7', AuditEvent::ALLOWED, 'succeeded');
}
$error = null;
try {
    (new LegacyAuditTrail($argv[1]))->record(event());
} catch (\RuntimeException $failure) {
    $error = $failure->getMessage();
}
$path = $argv[1] . '/log/kadupul-audit.jsonl';
echo json_encode(['violations' => $violations, 'mask' => \umask(), 'error' => $error, 'lines' => is_file($path) ? count(file($path)) : 0], JSON_THROW_ON_ERROR);
