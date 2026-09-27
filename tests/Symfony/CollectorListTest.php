<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\CollectorAdministration\Application\Port\CollectorCatalog;
use Kadupul\CollectorAdministration\Application\Query\CollectorAccessDenied;
use Kadupul\CollectorAdministration\Application\Query\ListCollectors;
use Kadupul\CollectorAdministration\Application\ReadModel\CollectorPage;
use Kadupul\CollectorAdministration\Domain\CollectorListCriteria;
use Kadupul\CollectorAdministration\Domain\CollectorStatus;
use Kadupul\CollectorAdministration\Infrastructure\Symfony\CollectorListParameters;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CollectorListTest extends TestCase
{
    #[DataProvider('deniedActors')]
    public function testAccessIsCheckedBeforeReadingCollectors(?Actor $actor): void
    {
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn($actor);
        $access->method('canManageDevices')->willReturn(false);
        $catalog = $this->createMock(CollectorCatalog::class);
        $catalog->expects(self::never())->method('list');

        try {
            (new ListCollectors($access, $catalog))(new CollectorListCriteria());
            self::fail('Expected access to be denied.');
        } catch (CollectorAccessDenied $error) {
            self::assertSame($actor === null, $error->unauthenticated);
        }
    }

    public static function deniedActors(): iterable
    {
        yield [null];
        yield [new Actor(42, 'viewer')];
    }

    public function testAuthorizedQueryReceivesValidatedCriteria(): void
    {
        $access = $this->createMock(ConsoleAccess::class);
        $actor = new Actor(42, 'operator');
        $access->method('consoleActor')->willReturn($actor);
        $access->expects(self::once())->method('canManageDevices')->with($actor)->willReturn(true);
        $catalog = $this->createMock(CollectorCatalog::class);
        $criteria = new CollectorListCriteria(' router ', 2, 50, 'hostname', 'desc');
        $page = new CollectorPage([], false);
        $catalog->expects(self::once())->method('list')->with($criteria)->willReturn($page);

        self::assertSame($page, (new ListCollectors($access, $catalog))($criteria));
        self::assertSame('router', $criteria->search);
        self::assertSame(50, $criteria->offset());
        self::assertEquals($criteria, CollectorListParameters::parse(
            ['page' => '2', 'collector_filter' => ['q' => ' router ', 'size' => '50', 'sort' => 'hostname', 'direction' => 'desc']],
            ['q' => ' router ', 'size' => '50', 'sort' => 'hostname', 'direction' => 'desc']
        ));
    }

    #[DataProvider('invalidCriteria')]
    public function testCriteriaRejectInvalidValues(array $arguments): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CollectorListCriteria(...$arguments);
    }

    public static function invalidCriteria(): iterable
    {
        yield [['', 0]];
        yield [['', 1, 26]];
        yield [['', 1, 25, 'unknown']];
        yield [['', 1, 25, 'name', 'sideways']];
        yield [[str_repeat('x', 201)]];
        yield [["bad\0query"]];
        yield [["\xff"]];
    }

    #[DataProvider('statusCases')]
    public function testLegacyStatusOverridesArePreserved(int $status, bool $disabled, int $heartbeat, string $expected): void
    {
        self::assertSame($expected, CollectorStatus::fromLegacy($status, $disabled, $heartbeat));
    }

    public static function statusCases(): iterable
    {
        yield [0, false, 10, 'New/Idle'];
        yield [1, false, 10, 'Running'];
        yield [3, false, 10, 'Down'];
        yield [5, false, 10, 'Recovering'];
        yield [1, false, 311, 'Heartbeat'];
        yield [1, true, 311, 'Disabled'];
    }

    public function testFilterParserRejectsUnexpectedAndMalformedParameters(): void
    {
        foreach ([
            [['page' => ['2']], []],
            [['unexpected' => 'value'], []],
            [['page' => '-1'], []],
            [['page' => '1000000'], []],
            [[], ['size' => ['100']]],
        ] as [$query, $formData]) {
            try {
                if (array_diff(array_keys($query), ['collector_filter', 'page']) !== []) {
                    CollectorListParameters::formData($query);
                }
                CollectorListParameters::parse($query, $formData);
                self::fail('Expected malformed filters to be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
