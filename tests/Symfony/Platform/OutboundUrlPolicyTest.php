<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Platform;

use Kadupul\Platform\Domain\OutboundUrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OutboundUrlPolicyTest extends TestCase
{
    public function testAllowsPublicHttpAndHttpsUrls(): void
    {
        $policy = new OutboundUrlPolicy();
        $policy->assertAllowed('https://example.org/api/v1/health');
        $policy->assertAllowed('http://93.184.216.34:8080/status');
        $policy->assertIpAllowed('93.184.216.34');
        $policy->assertIpAllowed('2606:2800:220:1:248:1893:25c8:1946');
        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{string}> */
    public static function blockedUrls(): iterable
    {
        yield 'empty' => [''];
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'gopher scheme' => ['gopher://127.0.0.1:6379/_INFO'];
        yield 'embedded credentials' => ['https://user:pass@example.org/'];
        yield 'newline header injection' => ["https://example.org/\r\nX-Injected: 1"];
        yield 'localhost' => ['http://localhost/admin'];
        yield 'subdomain localhost' => ['https://api.localhost/v1'];
        yield 'dot local' => ['https://printer.local/'];
        yield 'google cloud metadata hostname' => ['http://metadata.google.internal/computeMetadata/v1/'];
        yield 'ipv4 loopback' => ['http://127.0.0.1:8080/'];
        yield 'aws metadata link local' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'rfc1918 10/8' => ['http://10.0.0.5/'];
        yield 'rfc1918 172.16/12' => ['http://172.16.1.10/'];
        yield 'rfc1918 192.168/16' => ['http://192.168.1.1/'];
        yield 'cgnat 100.64/10' => ['http://100.64.0.1/'];
        yield 'ipv6 loopback' => ['http://[::1]/'];
        yield 'ipv6 link local' => ['http://[fe80::1]/'];
        yield 'ipv6 unique local' => ['http://[fc00::1]/'];
        yield 'ipv4 mapped ipv6 metadata' => ['http://[::ffff:169.254.169.254]/'];
    }

    #[DataProvider('blockedUrls')]
    public function testRejectsDisallowedUrlsByDefault(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new OutboundUrlPolicy())->assertAllowed($url);
    }

    public function testAllowsPrivateNetworkOnlyWhenExplicitlyEnabled(): void
    {
        $policy = new OutboundUrlPolicy();
        $policy->assertAllowed('https://10.20.30.40/redfish/v1', true);
        $policy->assertIpAllowed('10.20.30.40', true);
        $this->addToAssertionCount(1);

        $this->expectException(\InvalidArgumentException::class);
        $policy->assertAllowed('file:///etc/passwd', true);
    }
}
