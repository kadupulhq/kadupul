<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Infrastructure\Legacy\LegacyAuditTrail;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AuditTrailTest extends TestCase
{
    public function testRejectsPathReplacementDespiteCachedStat(): void
    {
        $root = sys_get_temp_dir() . '/kadupul-audit-' . bin2hex(random_bytes(8));
        mkdir($root . '/log', 0700, true);
        $path = $root . '/log/kadupul-audit.jsonl';
        file_put_contents($path, 'original');
        chmod($path, 0600);
        try {
            $probe = new Process([PHP_BINARY, __DIR__ . '/audit_rotation_probe.php', $root]);
            $probe->mustRun();
            self::assertSame('Audit path is not a private regular file.', $probe->getOutput());
            self::assertSame('original', file_get_contents($path . '.rotated'));
            self::assertSame('replacement', file_get_contents($path));
        } finally {
            @unlink($path);
            @unlink($path . '.rotated');
            @rmdir($root . '/log');
            @rmdir($root);
        }
    }

    public function testAppendsPrivateJsonLinesIndependentlyOfGenericLogging(): void
    {
        $root = sys_get_temp_dir() . '/kadupul-audit-' . bin2hex(random_bytes(8));
        mkdir($root . '/log', 0700, true);
        try {
            $trail = new LegacyAuditTrail($root);
            foreach (['succeeded', 'failed'] as $outcome) {
                $trail->record(new AuditEvent(
                    bin2hex(random_bytes(16)),
                    42,
                    'inventory.device.edit',
                    'device',
                    '7',
                    AuditEvent::ALLOWED,
                    $outcome,
                    '2026-09-23T04:00:00Z',
                ));
            }
            $path = $root . '/log/kadupul-audit.jsonl';
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            self::assertCount(2, $lines);
            self::assertSame(['succeeded', 'failed'], array_map(
                static fn(string $line): string => json_decode($line, true, 8, JSON_THROW_ON_ERROR)['outcome'],
                $lines,
            ));
            self::assertSame(0600, fileperms($path) & 0777);
        } finally {
            @unlink($root . '/log/kadupul-audit.jsonl');
            @rmdir($root . '/log');
            @rmdir($root);
        }
    }

    public function testRejectsSymlinkAuditPath(): void
    {
        $root = sys_get_temp_dir() . '/kadupul-audit-' . bin2hex(random_bytes(8));
        mkdir($root . '/log', 0700, true);
        file_put_contents($root . '/target', 'unchanged');
        symlink($root . '/target', $root . '/log/kadupul-audit.jsonl');
        try {
            $this->expectException(\RuntimeException::class);
            (new LegacyAuditTrail($root))->record(new AuditEvent(
                bin2hex(random_bytes(16)),
                null,
                'inventory.device.edit',
                'device',
                'unknown',
                AuditEvent::DENIED,
                AuditEvent::DENIED,
            ));
        } finally {
            @unlink($root . '/log/kadupul-audit.jsonl');
            @unlink($root . '/target');
            @rmdir($root . '/log');
            @rmdir($root);
        }
    }

    public function testRejectsExistingAuditFileWithPermissiveMode(): void
    {
        $root = sys_get_temp_dir() . '/kadupul-audit-' . bin2hex(random_bytes(8));
        mkdir($root . '/log', 0700, true);
        $path = $root . '/log/kadupul-audit.jsonl';
        file_put_contents($path, 'unchanged');
        chmod($path, 0644);
        try {
            $this->expectException(\RuntimeException::class);
            (new LegacyAuditTrail($root))->record(new AuditEvent(
                bin2hex(random_bytes(16)),
                null,
                'inventory.device.edit',
                'device',
                'unknown',
                AuditEvent::DENIED,
                AuditEvent::DENIED,
            ));
        } finally {
            self::assertSame('unchanged', file_get_contents($path));
            @unlink($path);
            @rmdir($root . '/log');
            @rmdir($root);
        }
    }

    public function testRejectsExistingAuditFileWithSpecialModeBits(): void
    {
        $root = sys_get_temp_dir() . '/kadupul-audit-' . bin2hex(random_bytes(8));
        mkdir($root . '/log', 0700, true);
        $path = $root . '/log/kadupul-audit.jsonl';
        file_put_contents($path, 'unchanged');
        chmod($path, 04600);
        try {
            $this->expectException(\RuntimeException::class);
            (new LegacyAuditTrail($root))->record(new AuditEvent(
                bin2hex(random_bytes(16)),
                null,
                'inventory.device.edit',
                'device',
                'unknown',
                AuditEvent::DENIED,
                AuditEvent::DENIED,
            ));
        } finally {
            self::assertSame('unchanged', file_get_contents($path));
            @unlink($path);
            @rmdir($root . '/log');
            @rmdir($root);
        }
    }
}
