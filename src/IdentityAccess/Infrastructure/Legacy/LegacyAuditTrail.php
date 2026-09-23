<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;

final readonly class LegacyAuditTrail implements AuditTrail
{
    public function __construct(private string $projectDir) {}

    public function record(AuditEvent $event): void
    {
        $directory = $this->projectDir . '/log';
        $path = $directory . '/kadupul-audit.jsonl';
        if (!is_dir($directory) || is_link($directory)) {
            throw new \RuntimeException('Audit directory is unavailable.');
        }
        if (is_link($path)) {
            throw new \RuntimeException('Audit path is not a regular file.');
        }
        $mask = umask(0177);
        try {
            $handle = @fopen($path, is_file($path) ? 'c+b' : 'x+b');
        } finally {
            umask($mask);
        }
        if ($handle === false) {
            throw new \RuntimeException('Audit sink is unavailable.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Audit sink lock failed.');
            }
            $file = fstat($handle);
            $pathEntry = lstat($path);
            if ($file === false || $pathEntry === false || ($file['mode'] & 0170000) !== 0100000
                || ($pathEntry['mode'] & 0170000) !== 0100000 || ($file['mode'] & 07777) !== 0600
                || $file['dev'] !== $pathEntry['dev'] || $file['ino'] !== $pathEntry['ino']) {
                throw new \RuntimeException('Audit path is not a private regular file.');
            }
            if (fseek($handle, 0, SEEK_END) !== 0) {
                throw new \RuntimeException('Audit sink seek failed.');
            }
            $line = $event->json() . PHP_EOL;
            for ($written = 0, $length = strlen($line); $written < $length;) {
                $bytes = fwrite($handle, substr($line, $written));
                if ($bytes === false || $bytes === 0) {
                    throw new \RuntimeException('Audit write failed.');
                }
                $written += $bytes;
            }
            if (!fflush($handle)) {
                throw new \RuntimeException('Audit flush failed.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
