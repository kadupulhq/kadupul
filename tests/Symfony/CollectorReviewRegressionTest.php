<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Exception\NoKeyValue;
use Kadupul\CollectorAdministration\Application\Port\CollectorCatalog;
use Kadupul\CollectorAdministration\Application\Port\CollectorTimezones;
use Kadupul\CollectorAdministration\Application\ReadModel\CollectorPage;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CollectorReviewRegressionTest extends TestCase
{
    #[DataProvider('denied')]
    public function testDeniedActorsCannotReadDatabase(?Actor $actor): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn($actor);
            $access->method('canManageDevices')->willReturn(false);
            $container->set(ConsoleAccess::class, $access);
            $catalog = $this->createMock(CollectorCatalog::class);
            $catalog->expects(self::never())->method('list');
            $catalog->expects(self::never())->method('defaultPageSize');
            $container->set(CollectorCatalog::class, $catalog);
            foreach (['/collectors', '/collectors/legacy', '/collectors/new', '/collectors/42/edit', '/collectors/timezones', '/collectors/actions/delete'] as $path) {
                $response = $kernel->handle(Request::create($path));
                self::assertSame($actor === null ? 401 : 403, $response->getStatusCode());
                self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            }
        } finally {
            $kernel->shutdown();
        }
    }

    public static function denied(): iterable
    {
        yield [null];
        yield [new Actor(42, 'restricted')];
    }

    public function testLegacyPostCannotReplayMutationsAndMalformedFiltersCannotRead(): void
    {
        $kernel = $this->authorizedKernel();
        try {
            $catalog = $this->createMock(CollectorCatalog::class);
            $catalog->expects(self::never())->method('list');
            $kernel->getContainer()->get('test.service_container')->set(CollectorCatalog::class, $catalog);
            self::assertSame(409, $kernel->handle(Request::create('/collectors/legacy', 'POST', ['action' => 'actions', 'selected_items' => 'serialized-selection', 'drp_action' => '1']))->getStatusCode());
            foreach (['/collectors?page[]=1', '/collectors?collector_filter[q][]=secret', '/collectors?collector_filter[unexpected]=1', '/collectors?collector_filter[refresh]=1', '/collectors/legacy?action[]=edit', '/collectors/legacy?sort_column[]=hostname', '/collectors/legacy?rows[]=25'] as $path) {
                self::assertSame(400, $kernel->handle(Request::create($path))->getStatusCode(), $path);
            }
            self::assertSame(405, $kernel->handle(Request::create('/collectors', 'POST'))->getStatusCode());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testLegacyNavigationPreservesFiltersWithoutFollowingReturnUrls(): void
    {
        $kernel = $this->authorizedKernel();
        try {
            $response = $kernel->handle(Request::create('/collectors/legacy?filter=router&rows=50&page=2&sort_column=poller.hostname&sort_direction=DESC&refresh=60&return=https://example.invalid'));
            self::assertSame(302, $response->getStatusCode());
            parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
            self::assertSame(['q' => 'router', 'size' => '50', 'sort' => 'hostname', 'direction' => 'desc', 'refresh' => '60'], $query['collector_filter']);
            self::assertSame('2', $query['page']);
            self::assertArrayNotHasKey('return', $query);
        } finally {
            $kernel->shutdown();
        }
    }

    public function testDatabaseFailureDoesNotExposeConnectionDetails(): void
    {
        $kernel = $this->authorizedKernel();
        try {
            $catalog = $this->createMock(CollectorCatalog::class);
            $catalog->method('list')->willThrowException(new NoKeyValue('sensitive-connection-diagnostic'));
            $kernel->getContainer()->get('test.service_container')->set(CollectorCatalog::class, $catalog);
            $response = $kernel->handle(Request::create('/collectors'));
            self::assertSame(502, $response->getStatusCode());
            self::assertStringNotContainsString('sensitive-connection-diagnostic', $response->getContent());
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testEmptyListAndRefreshOffWorkWithPartialGetForms(): void
    {
        $kernel = $this->authorizedKernel();
        try {
            $catalog = $this->createMock(CollectorCatalog::class);
            $catalog->method('list')->willReturn(new CollectorPage([], false));
            $kernel->getContainer()->get('test.service_container')->set(CollectorCatalog::class, $catalog);
            $response = $kernel->handle(Request::create('/collectors?collector_filter[refresh]=0'));
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('No Data Collectors Found', $response->getContent());
            self::assertFalse($response->headers->has('Refresh'));
            self::assertStringNotContainsString('rel="next"', $response->getContent());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testConfiguredPageSizeIsUsedUnlessExplicitlyOverridden(): void
    {
        $kernel = $this->authorizedKernel();
        try {
            $catalog = $this->createMock(CollectorCatalog::class);
            $catalog->method('defaultPageSize')->willReturn(50);
            $catalog->expects(self::exactly(2))->method('list')->willReturnCallback(static function ($criteria): CollectorPage {
                self::assertContains($criteria->pageSize, [50, 15]);
                return new CollectorPage([], false);
            });
            $kernel->getContainer()->get('test.service_container')->set(CollectorCatalog::class, $catalog);
            foreach (['/collectors', '/collectors?collector_filter[size]=15'] as $path) {
                self::assertSame(200, $kernel->handle(Request::create($path))->getStatusCode());
            }
        } finally {
            $kernel->shutdown();
        }
    }

    public function testTimezoneDatabaseFailureIsControlledAndRedacted(): void
    {
        $kernel = $this->authorizedKernel();
        try {
            $timezones = $this->createMock(CollectorTimezones::class);
            $timezones->method('search')->willThrowException(new NoKeyValue('sensitive-timezone-diagnostic'));
            $kernel->getContainer()->get('test.service_container')->set(CollectorTimezones::class, $timezones);
            $response = $kernel->handle(Request::create('/collectors/timezones?term=UTC'));
            self::assertSame(502, $response->getStatusCode());
            self::assertStringNotContainsString('sensitive-timezone-diagnostic', $response->getContent());
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testEditorAssetIsAvailableWithoutInstallationAccess(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $response = $kernel->handle(Request::create('/collector-editor.js'));
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('text/javascript; charset=UTF-8', $response->headers->get('Content-Type'));
            self::assertSame(file_get_contents(dirname(__DIR__, 2) . '/public/collector-editor.js'), $response->getContent());
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        } finally {
            $kernel->shutdown();
        }
    }

    private function authorizedKernel(): Kernel
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
        $access->method('canManageDevices')->willReturn(true);
        $kernel->getContainer()->get('test.service_container')->set(ConsoleAccess::class, $access);
        return $kernel;
    }
}
