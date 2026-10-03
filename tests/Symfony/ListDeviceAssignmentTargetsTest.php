<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceCollectorAssignments;
use Kadupul\Inventory\Application\Port\DeviceTemplateAssignments;
use Kadupul\Inventory\Application\Port\SiteAssignmentCatalog;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Application\Query\ListDeviceAssignmentTargets;
use PHPUnit\Framework\TestCase;

final class ListDeviceAssignmentTargetsTest extends TestCase
{
    public function testAuthorizationPrecedesEveryCatalogRead(): void
    {
        foreach ([null, new Actor(7, 'operator')] as $actor) {
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn($actor);
            $access->expects($actor === null ? self::never() : self::once())->method('canManageDevices')->willReturn(false);
            $collectors = $this->createMock(DeviceCollectorAssignments::class);
            $templates = $this->createMock(DeviceTemplateAssignments::class);
            $sites = $this->createMock(SiteAssignmentCatalog::class);
            $collectors->expects(self::never())->method('collectors');
            $templates->expects(self::never())->method('templates');
            $sites->expects(self::never())->method('sites');
            try {
                (new ListDeviceAssignmentTargets($access, $collectors, $templates, $sites))('site');
                self::fail('Unauthorized catalog read succeeded');
            } catch (InventoryAccessDenied $error) {
                self::assertSame($actor === null, $error->unauthenticated);
            }
        }
    }

    public function testEachKindReadsOnlyItsOwnCatalog(): void
    {
        foreach (['site', 'template', 'collector', 'unknown'] as $kind) {
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn(new Actor(7, 'operator'));
            $access->method('canManageDevices')->willReturn(true);
            $collectors = $this->createMock(DeviceCollectorAssignments::class);
            $templates = $this->createMock(DeviceTemplateAssignments::class);
            $sites = $this->createMock(SiteAssignmentCatalog::class);
            foreach ([['collector', $collectors, 'collectors'], ['template', $templates, 'templates'], ['site', $sites, 'sites']] as [$candidate, $port, $method]) {
                $port->expects($candidate === $kind ? self::once() : self::never())->method($method)->willReturn([9 => $candidate]);
            }
            try {
                self::assertSame([9 => $kind], (new ListDeviceAssignmentTargets($access, $collectors, $templates, $sites))($kind));
                self::assertNotSame('unknown', $kind);
            } catch (\InvalidArgumentException) {
                self::assertSame('unknown', $kind);
            }
        }
    }
}
