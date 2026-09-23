<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Infrastructure\Legacy\DeviceDiagnosticText;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceDiagnosticScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceDiagnosticRedactionTest extends TestCase
{
    #[DataProvider('representations')]
    public function testLegacyCredentialRepresentationsAreRedacted(string $secret, string $rendered): void
    {
        self::assertSame('command -A [redacted] done', DeviceDiagnosticText::clean('command -A ' . $rendered . ' done', ['snmp_password' => $secret]));
    }

    public static function representations(): iterable
    {
        yield 'literal' => ['fixture-secret', 'fixture-secret'];
        yield 'Unix apostrophe' => ["fixture'quoted", "'fixture'\\''quoted'"];
        yield 'Windows quote' => ['fixture"quoted', '"fixture\\"quoted"'];
        yield 'Unix CRLF normalization' => ["fixture\r\n'quoted", "'fixture'\\''quoted'"];
        yield 'HTML-encoded Unix command' => ["fixture'quoted", '&#039;fixture&#039;\\&#039;&#039;quoted&#039;'];
        yield 'markup-like credential' => ['fixture<tag>secret', 'fixture<tag>secret'];
        yield 'HTML-encoded markup credential' => ['fixture<tag>secret', 'fixture&lt;tag&gt;secret'];
        yield 'numeric-encoded markup credential' => ['fixture<tag>secret', 'fixture&#60;tag&#62;secret'];
        yield 'encoded markup and Unix quote' => ["fixture<tag>'secret", '&#039;fixture&lt;tag&gt;&#039;\\&#039;&#039;secret&#039;'];
    }

    public function testCollectorScopeRedactsEveryCredentialUsedDuringRotationAndClearsState(): void
    {
        DeviceDiagnosticScope::begin();
        DeviceDiagnosticScope::remember(['snmp_password' => 'old-secret']);
        DeviceDiagnosticScope::remember(['snmp_password' => 'rotated-secret']);
        self::assertSame(['result' => true, 'data_query' => ['[redacted] [redacted]']], DeviceDiagnosticScope::finish(['result' => true, 'data_query' => ['old-secret rotated-secret']]));
        DeviceDiagnosticScope::remember(['snmp_password' => 'ignored-outside-scope']);
        DeviceDiagnosticScope::begin();
        self::assertSame(['output' => 'old-secret ignored-outside-scope'], DeviceDiagnosticScope::finish(['output' => 'old-secret ignored-outside-scope']));
        $this->expectException(\LogicException::class);
        DeviceDiagnosticScope::finish([]);
    }

    public function testCollectorScopeFailsClosedWhenTooManyCredentialsAreUsed(): void
    {
        DeviceDiagnosticScope::begin();
        try {
            $this->expectException(\RuntimeException::class);
            for ($i = 0; $i <= 512; ++$i) {
                DeviceDiagnosticScope::remember(['snmp_password' => 'secret-' . $i]);
            }
        } finally {
            DeviceDiagnosticScope::discard();
        }
    }

    public function testOverlappingCredentialsAreReplacedInOnePass(): void
    {
        self::assertSame('[redacted] [redacted]', DeviceDiagnosticText::clean('abc-long abc', ['snmp_community' => 'abc', 'snmp_password' => 'abc-long']));
    }
}
