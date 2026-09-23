<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Contract\AuditEvent;

require dirname(__DIR__, 2) . '/include/vendor/autoload.php';

// This shim exists only in this subprocess, never in the PHPUnit process.
function flock($stream, int $operation): bool
{
    $locked = \flock($stream, $operation);
    if ($locked && $operation === LOCK_EX) {
        $path = stream_get_meta_data($stream)['uri'];
        // Prime the pathname cache before another process replaces the inode.
        \lstat($path);
        $replacement = proc_open([PHP_BINARY, '-r',
            'rename($argv[1], $argv[1] . ".rotated"); file_put_contents($argv[1], "replacement"); chmod($argv[1], 0600);',
            $path,
        ], [], $pipes);
        if (!is_resource($replacement) || proc_close($replacement) !== 0) {
            throw new \LogicException('Could not replace the audit path.');
        }
        if (\lstat($path)['ino'] !== \fstat($stream)['ino']) {
            throw new \LogicException('Probe did not retain the stale pathname cache.');
        }
    }

    return $locked;
}

try {
    (new LegacyAuditTrail($argv[1]))->record(new AuditEvent(
        bin2hex(random_bytes(16)),
        null,
        'inventory.device.edit',
        'device',
        'unknown',
        AuditEvent::DENIED,
        AuditEvent::DENIED,
    ));
    echo 'accepted';
} catch (\RuntimeException $exception) {
    echo $exception->getMessage();
}
