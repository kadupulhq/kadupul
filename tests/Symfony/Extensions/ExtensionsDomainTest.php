<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Extensions;

use Kadupul\Extensions\Domain\PluginMetadata;
use Kadupul\Extensions\Domain\PluginRealmPolicy;
use Kadupul\Extensions\Domain\PluginSlug;
use Kadupul\Extensions\Domain\PluginStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExtensionsDomainTest extends TestCase
{
    public function testPluginSlugAcceptsValidIdentifiers(): void
    {
        $slug = PluginSlug::fromString('thold_v2-beta');
        $this->assertSame('thold_v2-beta', $slug->value());
        $this->assertSame('thold_v2-beta', (string) $slug);
        $this->assertTrue($slug->equals(PluginSlug::fromString('thold_v2-beta')));
        $this->assertFalse($slug->equals(PluginSlug::fromString('monitor')));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSlugs(): iterable
    {
        yield 'empty' => [''];
        yield 'traversal' => ['../etc'];
        yield 'dot' => ['plugin.name'];
        yield 'slash' => ['a/b'];
        yield 'leading hyphen' => ['-plugin'];
        yield 'leading underscore' => ['_plugin'];
        yield 'too long' => [str_repeat('a', 33)];
        yield 'space' => ['my plugin'];
    }

    #[DataProvider('invalidSlugs')]
    public function testPluginSlugRejectsUnsafeValues(string $raw): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PluginSlug::fromString($raw);
    }

    public function testPluginStatusMapsLegacyStatusCodes(): void
    {
        $this->assertSame(PluginStatus::Discovered, PluginStatus::fromLegacyStatus(0));
        $this->assertSame(PluginStatus::Enabled, PluginStatus::fromLegacyStatus(1));
        $this->assertSame(PluginStatus::Installed, PluginStatus::fromLegacyStatus(2));
        $this->assertSame(PluginStatus::Installed, PluginStatus::fromLegacyStatus(3));
        $this->assertSame(PluginStatus::Disabled, PluginStatus::fromLegacyStatus(4));
        $this->assertSame(PluginStatus::Disabled, PluginStatus::fromLegacyStatus(-1));

        $this->assertTrue(PluginStatus::Enabled->isActive());
        $this->assertFalse(PluginStatus::Disabled->isActive());
        $this->assertFalse(PluginStatus::Installed->isActive());
        $this->assertFalse(PluginStatus::Discovered->isActive());
    }

    public function testPluginMetadataValidatesWebpageScheme(): void
    {
        $meta = new PluginMetadata(
            PluginSlug::fromString('monitor'),
            'Device Monitor',
            '2.7',
            'Kadupul',
            'https://example.org/monitor',
        );
        $this->assertSame('https://example.org/monitor', $meta->webpage);
        $this->assertNull(PluginMetadata::sanitizeWebpage('javascript:alert(1)'));
        $this->assertNull(PluginMetadata::sanitizeWebpage(''));
        $this->assertSame('http://example.org', PluginMetadata::sanitizeWebpage('http://example.org'));

        $this->expectException(\InvalidArgumentException::class);
        new PluginMetadata(PluginSlug::fromString('monitor'), 'Monitor', '1.0', '', 'javascript:alert(1)');
    }

    public function testPluginRealmPolicyProtectsCoreAndReservedRealms(): void
    {
        $policy = new PluginRealmPolicy();

        $this->assertTrue($policy->isCoreRealm(0));
        $this->assertTrue($policy->isCoreRealm(15));
        $this->assertTrue($policy->isCoreRealm(99));
        $this->assertFalse($policy->isCoreRealm(100));

        $this->assertTrue($policy->canRegisterRealm(100, PluginStatus::Enabled));
        $this->assertTrue($policy->canRegisterRealm(9999, PluginStatus::Enabled));
        $this->assertFalse($policy->canRegisterRealm(100, PluginStatus::Disabled));
        $this->assertFalse($policy->canRegisterRealm(100, PluginStatus::Installed));
        $this->assertFalse($policy->canRegisterRealm(15, PluginStatus::Enabled));
        $this->assertFalse($policy->canRegisterRealm(10000, PluginStatus::Enabled));

        $policy->assertRealmRegistrable(105, PluginStatus::Enabled);
    }

    public function testPluginRealmPolicyThrowsOnCoreRealmOverwrite(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('core realm 15');
        (new PluginRealmPolicy())->assertRealmRegistrable(15, PluginStatus::Enabled);
    }

    public function testPluginRealmPolicyThrowsOnDisabledPlugin(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Inactive plugin');
        (new PluginRealmPolicy())->assertRealmRegistrable(150, PluginStatus::Disabled);
    }
}
