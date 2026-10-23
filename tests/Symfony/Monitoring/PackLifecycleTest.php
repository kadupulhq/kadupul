<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

use Kadupul\Monitoring\Application\PackLifecycle;
use Kadupul\Monitoring\Domain\InvalidPack;
use Kadupul\Monitoring\Domain\PackLifecycleViolation;
use Kadupul\Monitoring\Domain\PackState;
use Kadupul\Monitoring\Domain\PackValidator;
use Kadupul\Monitoring\Infrastructure\Persistence\InMemoryPackRepository;
use PHPUnit\Framework\TestCase;

final class PackLifecycleTest extends TestCase
{
    private InMemoryPackRepository $packs;
    private PackLifecycle $lifecycle;

    protected function setUp(): void
    {
        $this->packs = new InMemoryPackRepository();
        $this->lifecycle = new PackLifecycle($this->packs, new PackValidator('1.2.35'));
    }

    public function testAValidPackIsInstalledButNotActive(): void
    {
        $installed = $this->lifecycle->install(PackDocuments::example(), 'example.pack.json');

        self::assertSame(PackState::Installed, $installed->state);
        self::assertSame('1.0.0', $this->packs->find('openconfig-interfaces')?->pack->version->toString());
    }

    public function testAnInvalidPackIsNotInstalled(): void
    {
        $document = PackDocuments::example();
        $document->metrics[3]->counter_bits = 32;

        try {
            $this->lifecycle->install($document, 'bad.pack.json');
            self::fail('An invalid pack was installed.');
        } catch (InvalidPack) {
            self::assertSame([], $this->packs->all());
        }
    }

    public function testInstallingAnInstalledPackAgainIsRefused(): void
    {
        $this->lifecycle->install(PackDocuments::example(), 'example.pack.json');

        $this->expectException(PackLifecycleViolation::class);
        $this->expectExceptionMessage('already installed at 1.0.0');

        $this->lifecycle->install(PackDocuments::exampleAt('1.1.0'), 'example.pack.json');
    }

    public function testAMinorUpgradeThatAddsAMetricKeepsAnActivePackActive(): void
    {
        $this->installActive();

        $upgrade = $this->lifecycle->upgrade(PackDocuments::exampleAt('1.1.0', PackDocuments::addingOutDiscards()), 'v1.1.pack.json');

        self::assertSame('1.0.0', $upgrade->previousVersion->toString());
        self::assertSame(['interface.out_discards'], $upgrade->addedMetrics);
        self::assertSame([], $upgrade->removedMetrics);
        self::assertSame(PackState::Active, $upgrade->pack->state);
        self::assertSame('1.1.0', $this->packs->find('openconfig-interfaces')?->pack->version->toString());
    }

    public function testAMajorUpgradeThatRemovesAMetricStaysActiveAndReportsTheRemoval(): void
    {
        $this->installActive();

        $upgrade = $this->lifecycle->upgrade(PackDocuments::exampleAt('2.0.0', PackDocuments::removingErrors()), 'v2.pack.json');

        self::assertSame(['interface.in_errors'], $upgrade->removedMetrics);
        self::assertSame(PackState::Active, $upgrade->pack->state);
    }

    public function testRemovingAMetricInAMinorUpgradeIsRefusedAndNothingChanges(): void
    {
        $this->installActive();

        try {
            $this->lifecycle->upgrade(PackDocuments::exampleAt('1.1.0', PackDocuments::removingErrors()), 'v1.1.pack.json');
            self::fail('A breaking minor upgrade was accepted.');
        } catch (PackLifecycleViolation $e) {
            self::assertStringContainsString('interface.in_errors', $e->getMessage());
            self::assertStringContainsString('major version', $e->getMessage());
        }
        self::assertSame('1.0.0', $this->packs->find('openconfig-interfaces')?->pack->version->toString());
        self::assertSame(PackState::Active, $this->packs->find('openconfig-interfaces')?->state);
    }

    public function testChangingACounterWidthInAMinorUpgradeIsRefused(): void
    {
        $this->lifecycle->install(PackDocuments::example(), 'example.pack.json');

        $this->expectException(PackLifecycleViolation::class);
        $this->expectExceptionMessage('interface.in_errors');

        $this->lifecycle->upgrade(PackDocuments::exampleAt('1.1.0', static function (\stdClass $d): void {
            $d->metrics[2]->counter_bits = 64;
        }), 'v1.1.pack.json');
    }

    public function testVersionsOnlyMoveForward(): void
    {
        $this->lifecycle->install(PackDocuments::exampleAt('1.2.0'), 'example.pack.json');

        foreach (['1.2.0', '1.1.9', '1.2.0-rc.1'] as $version) {
            try {
                $this->lifecycle->upgrade(PackDocuments::exampleAt($version), 'older.pack.json');
                self::fail($version . ' was accepted as an upgrade of 1.2.0.');
            } catch (PackLifecycleViolation $e) {
                self::assertStringContainsString('newer than 1.2.0', $e->getMessage());
            }
        }
        self::assertSame('1.2.0', $this->packs->find('openconfig-interfaces')?->pack->version->toString());
    }

    public function testAnInvalidUpgradeLeavesTheInstalledPackAlone(): void
    {
        $this->installActive();

        try {
            $this->lifecycle->upgrade(PackDocuments::exampleAt('1.1.0', static function (\stdClass $d): void {
                $d->collection->interval_seconds->default = 1;
            }), 'v1.1.pack.json');
            self::fail('An invalid upgrade was accepted.');
        } catch (InvalidPack) {
        }
        self::assertSame('1.0.0', $this->packs->find('openconfig-interfaces')?->pack->version->toString());
        self::assertSame(PackState::Active, $this->packs->find('openconfig-interfaces')?->state);
    }

    public function testUpgradingAPackThatIsNotInstalledIsRefused(): void
    {
        $this->expectException(PackLifecycleViolation::class);
        $this->expectExceptionMessage('not installed');

        $this->lifecycle->upgrade(PackDocuments::example(), 'example.pack.json');
    }

    public function testDeprecationKeepsThePackButMarksIt(): void
    {
        $this->installActive();

        $deprecated = $this->lifecycle->deprecate('openconfig-interfaces');

        self::assertSame(PackState::Deprecated, $deprecated->state);
        self::assertSame(PackState::Deprecated, $this->packs->find('openconfig-interfaces')?->state);
        $this->expectException(PackLifecycleViolation::class);
        $this->lifecycle->deprecate('unknown-pack');
    }

    private function installActive(): void
    {
        $installed = $this->lifecycle->install(PackDocuments::example(), 'example.pack.json');
        $this->packs->save($installed->withState(PackState::Active));
    }
}
