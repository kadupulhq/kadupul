<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Collection;

use Kadupul\Collection\Domain\CollectorDeletionPolicy;
use Kadupul\Collection\Domain\CollectorId;
use Kadupul\Collection\Domain\CollectorStatus;
use Kadupul\Collection\Domain\PollingInterval;
use Kadupul\Collection\Domain\SnmpVersion;
use PHPUnit\Framework\TestCase;

final class CollectionDomainTest extends TestCase
{
    public function testCollectorIdEnforcesBoundsAndPrimaryIdentity(): void
    {
        $primary = CollectorId::primary();
        $this->assertSame(1, $primary->toInt());
        $this->assertTrue($primary->isPrimary());
        $this->assertTrue($primary->equals(CollectorId::fromInt(1)));

        $remote = CollectorId::fromInt(4);
        $this->assertFalse($remote->isPrimary());
        $this->assertSame(4, $remote->toInt());

        $this->expectException(\InvalidArgumentException::class);
        CollectorId::fromInt(0);
    }

    public function testCollectorIdRejectsOverflow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CollectorId::fromInt(65536);
    }

    public function testCollectorStatusHealthAndLabels(): void
    {
        $this->assertTrue(CollectorStatus::Running->isHealthy());
        $this->assertTrue(CollectorStatus::Idle->isHealthy());
        $this->assertTrue(CollectorStatus::Heartbeat->isHealthy());
        $this->assertFalse(CollectorStatus::Down->isHealthy());
        $this->assertFalse(CollectorStatus::Disabled->isHealthy());
        $this->assertFalse(CollectorStatus::New->isHealthy());
        $this->assertFalse(CollectorStatus::Recovering->isHealthy());
        $this->assertSame('idle', CollectorStatus::Idle->label());
        $this->assertSame('recovering', CollectorStatus::Recovering->label());
    }

    public function testSnmpVersionCapabilities(): void
    {
        $this->assertFalse(SnmpVersion::None->isEnabled());
        $this->assertTrue(SnmpVersion::V1->usesCommunityString());
        $this->assertTrue(SnmpVersion::V2c->usesCommunityString());
        $this->assertFalse(SnmpVersion::V3->usesCommunityString());
        $this->assertTrue(SnmpVersion::V3->usesUsm());
        $this->assertFalse(SnmpVersion::V2c->usesUsm());
    }

    public function testPollingIntervalBoundsAndStandardValues(): void
    {
        $default = PollingInterval::default();
        $this->assertSame(300, $default->seconds());
        $this->assertTrue($default->isStandard());
        $this->assertTrue($default->equals(PollingInterval::fromSeconds(300)));

        $custom = PollingInterval::fromSeconds(45);
        $this->assertFalse($custom->isStandard());

        $this->expectException(\InvalidArgumentException::class);
        PollingInterval::fromSeconds(0);
    }

    public function testCollectorDeletionPolicyProtectsPrimaryCollector(): void
    {
        $policy = new CollectorDeletionPolicy();
        $primary = CollectorId::primary();
        $remote = CollectorId::fromInt(2);

        $this->assertFalse($policy->canDelete($primary));
        $this->assertFalse($policy->canDisable($primary));
        $this->assertTrue($policy->canDelete($remote));
        $this->assertTrue($policy->canDisable($remote));

        $policy->assertDeletable($remote);
        $policy->assertDisableable($remote);

        $this->expectException(\DomainException::class);
        $policy->assertDeletable($primary);
    }

    public function testCollectorDeletionPolicyRefusesDisablingPrimaryCollector(): void
    {
        $this->expectException(\DomainException::class);
        (new CollectorDeletionPolicy())->assertDisableable(CollectorId::primary());
    }
}
