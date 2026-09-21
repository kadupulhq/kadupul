<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Command\EditSite;
use Kadupul\Inventory\Application\Command\SiteNotFound;
use Kadupul\Inventory\Application\Port\SiteEditor;
use Kadupul\Inventory\Application\Query\FindEditableSite;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\Site;
use Kadupul\Inventory\Domain\SiteEditConflict;
use Kadupul\Inventory\Infrastructure\Symfony\SiteListParameters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SiteEditTest extends TestCase
{
    public function testSiteRevisionAndUnicodeBounds(): void
    {
        $site = new Site(12, 'Old', '');
        $revision = $site->revision();
        $site->revise(' ' . str_repeat('🌏', 100) . ' ', str_repeat('🌟', 1024), $revision);
        self::assertSame(str_repeat('🌏', 100), $site->name());
        self::assertSame(str_repeat('🌟', 1024), $site->notes());
        self::assertNotSame($revision, $site->revision());
        $revision = $site->revision();
        $site->revise($site->name(), '', $revision);
        self::assertNotSame($revision, $site->revision());
        self::assertSame('', $site->notes());
    }

    #[DataProvider('invalidText')]
    public function testValidationIsAtomic(string $name, string $notes): void
    {
        $site = new Site(12, 'Old', 'Original');
        $revision = $site->revision();
        try {
            $site->revise($name, $notes, $revision);
            self::fail('Invalid site text was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame($revision, $site->revision());
        }
    }

    public static function invalidText(): iterable
    {
        foreach ([' ', str_repeat('東', 101), "a\0b", "\0Name", "Name\0", "\xff"] as $name) {
            yield [$name, 'Valid'];
        }
        foreach ([str_repeat('東', 1025), "a\0b", "\xff"] as $notes) {
            yield ['Changed', $notes];
        }
    }

    public function testStaleRevisionCannotMutateSite(): void
    {
        $site = new Site(12, 'Original', 'Original');
        $revision = $site->revision();
        try {
            $site->revise('Changed', '', 'old-revision');
            self::fail('Stale edit accepted.');
        } catch (SiteEditConflict) {
            self::assertSame($revision, $site->revision());
        }
    }

    #[DataProvider('deniedActors')]
    public function testQueryAndCommandAuthorizeBeforeReading(?Actor $actor): void
    {
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn($actor);
        $access->method('canManageDevices')->willReturn(false);
        $sites = $this->createMock(SiteEditor::class);
        $sites->expects(self::never())->method('find');
        $sites->expects(self::never())->method('save');
        try {
            (new FindEditableSite($access, $sites))(12);
            self::fail('Unauthorized read accepted.');
        } catch (InventoryAccessDenied $error) {
            self::assertSame($actor === null, $error->unauthenticated);
        }
        $this->expectException(InventoryAccessDenied::class);
        (new EditSite($access, $sites))(12, 'Name', '', 'revision');
    }

    public static function deniedActors(): iterable
    {
        yield [null];
        yield [new Actor(42, 'viewer')];
    }

    public function testQueryAndCommandUseSitePortAndCurrentActor(): void
    {
        $access = $this->authorized();
        $site = new Site(12, 'Original', 'Original');
        $revision = $site->revision();
        $sites = $this->createMock(SiteEditor::class);
        $sites->expects(self::exactly(2))->method('find')->with(12)->willReturn($site);
        $sites->expects(self::once())->method('save')->with(42, $site, $revision);
        self::assertSame($site, (new FindEditableSite($access, $sites))(12));
        (new EditSite($access, $sites))(12, 'Changed', 'Notes', $revision);
        self::assertSame('Changed', $site->name());
        self::assertSame('Notes', $site->notes());
    }

    public function testMissingSiteIsNeverCreated(): void
    {
        $sites = $this->createMock(SiteEditor::class);
        $sites->method('find')->willReturn(null);
        $sites->expects(self::never())->method('save');
        self::assertNull((new FindEditableSite($this->authorized(), $sites))(12));
        $this->expectException(SiteNotFound::class);
        (new EditSite($this->authorized(), $sites))(12, 'Name', '', 'revision');
    }

    public function testNavigationContextIsValidatedAndCannotSupplyReturnUrl(): void
    {
        self::assertSame(['q' => 'Tokyo', 'page' => 2, 'size' => 50, 'direction' => 'desc'], SiteListParameters::context(['list' => ['q' => 'Tokyo', 'page' => '2', 'size' => '50', 'direction' => 'desc', 'return_url' => 'https://attacker.invalid/']]));
        $this->expectException(\InvalidArgumentException::class);
        SiteListParameters::context(['list' => 'invalid']);
    }

    private function authorized(): ConsoleAccess
    {
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
        $access->method('canManageDevices')->willReturn(true);
        return $access;
    }
}
