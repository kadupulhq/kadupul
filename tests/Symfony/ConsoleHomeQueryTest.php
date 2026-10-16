<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuthenticatedAccess;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Application\Port\ConsoleHomeContent;
use Kadupul\Platform\Application\Port\ProductVersion;
use Kadupul\Platform\Application\Query\ConsoleHomeAccessDenied;
use Kadupul\Platform\Application\Query\ViewConsoleHome;
use Kadupul\Platform\Application\ReadModel\ConsoleCollectorStatus;
use Kadupul\Platform\Application\ReadModel\ConsoleHomeContent as Content;
use Kadupul\Platform\Application\ReadModel\ProductRelease;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConsoleHomeQueryTest extends TestCase
{
    public static function refusedActors(): iterable
    {
        yield 'anonymous' => [null, null, true];
        yield 'no console realm' => [new Actor(3, 'operator'), null, false];
        yield 'identity changed between checks' => [new Actor(3, 'operator'), new Actor(4, 'other'), false];
    }

    #[DataProvider('refusedActors')]
    public function testRefusedVisitorsCannotReadHomeContentOrRelease(?Actor $actor, ?Actor $consoleActor, bool $anonymous): void
    {
        $authenticated = $this->createMock(AuthenticatedAccess::class);
        $authenticated->method('authenticatedActor')->willReturn($actor);
        $console = $this->createMock(ConsoleAccess::class);
        $console->method('consoleActor')->willReturn($consoleActor);
        $content = $this->createMock(ConsoleHomeContent::class);
        $content->expects(self::never())->method('forActor');
        $version = $this->createMock(ProductVersion::class);
        $version->expects(self::never())->method('release');

        try {
            (new ViewConsoleHome($authenticated, $console, $content, $version))();
            self::fail('Expected denied access.');
        } catch (ConsoleHomeAccessDenied $denied) {
            self::assertSame($anonymous, $denied->anonymous);
        }
    }

    public function testAuthorizedConsoleReceivesTheCollectorStateWithoutHttpOrSessionDependencies(): void
    {
        $actor = new Actor(3, 'operator');
        $authenticated = $this->createMock(AuthenticatedAccess::class);
        $authenticated->method('authenticatedActor')->willReturn($actor);
        $console = $this->createMock(ConsoleAccess::class);
        $console->method('consoleActor')->willReturn($actor);
        $status = new Content(false, new ConsoleCollectorStatus('recovery', 37));
        $content = $this->createMock(ConsoleHomeContent::class);
        $content->expects(self::once())->method('forActor')->with($actor)->willReturn($status);
        $release = new ProductRelease('1.3.0', '1');
        $version = $this->createMock(ProductVersion::class);
        $version->method('release')->willReturn($release);

        $home = (new ViewConsoleHome($authenticated, $console, $content, $version))();

        self::assertSame($release, $home->release);
        self::assertSame($status, $home->content);
    }
}
