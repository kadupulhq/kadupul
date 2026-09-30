<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\CollectorAdministration\Domain\CollectorRevision;
use Kadupul\CollectorAdministration\Infrastructure\Legacy\CollectorRevisionKey;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;

final class CollectorRevisionKeyTest extends TestCase
{
    public function testItDerivesOpaqueRevisionTokensFromTheInstalledCsrfSecret(): void
    {
        $root = sys_get_temp_dir() . '/collector-revision-' . bin2hex(random_bytes(6));
        mkdir($root . '/include/vendor/csrf', 0700, true);
        $secret = random_bytes(48);
        file_put_contents($root . '/include/vendor/csrf/csrf-secret.php', $secret);
        chmod($root . '/include/vendor/csrf/csrf-secret.php', 0600);
        $configuration = $this->configuration($root);

        try {
            $key = (new CollectorRevisionKey($configuration))->get();
            self::assertSame(32, strlen($key));
            self::assertNotSame($secret, $key);
            $base = ['name' => 'Collector', 'hostname' => 'collector.example', 'dbssl' => 'on', 'processes' => '2'];
            $first = CollectorRevision::fromValues($base, 'stored-password-marker', $key);
            self::assertSame($first, CollectorRevision::fromValues($base, 'stored-password-marker', $key));
            self::assertNotSame($first, CollectorRevision::fromValues($base, 'changed-password-marker', $key));
            self::assertNotSame($first, CollectorRevision::fromValues(array_replace($base, ['hostname' => 'changed.example']), 'stored-password-marker', $key));
            self::assertStringNotContainsString('stored-password-marker', $first);
        } finally {
            unlink($root . '/include/vendor/csrf/csrf-secret.php');
            rmdir($root . '/include/vendor/csrf');
            rmdir($root . '/include/vendor');
            rmdir($root . '/include');
            rmdir($root);
        }
    }

    public function testMissingOrUntrustedInstalledSecretsFailClosed(): void
    {
        $root = sys_get_temp_dir() . '/collector-revision-' . bin2hex(random_bytes(6));
        mkdir($root . '/include/vendor/csrf', 0700, true);
        $configuration = $this->configuration($root);

        try {
            $this->expectException(\RuntimeException::class);
            (new CollectorRevisionKey($configuration))->get();
        } finally {
            rmdir($root . '/include/vendor/csrf');
            rmdir($root . '/include/vendor');
            rmdir($root . '/include');
            rmdir($root);
        }
    }

    private function configuration(string $root): LegacyConfiguration
    {
        return new class ($root) implements LegacyConfiguration {
            public function __construct(private readonly string $root) {}
            public function values(): array
            {
                return ['root' => $this->root];
            }
        };
    }
}
