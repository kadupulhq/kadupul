<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Kernel;
use Kadupul\Platform\Application\Port\RrdCheckAccess;
use Kadupul\Platform\Application\Port\RrdCheckPreferences;
use Kadupul\Platform\Application\Port\RrdCheckStore;
use Kadupul\Platform\Application\Query\RrdCheckAccessDenied;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Domain\RrdCheck\RrdCheckFilters;
use Kadupul\Platform\Domain\RrdCheck\RrdCheckPage;
use Kadupul\Platform\Domain\RrdCheck\RrdCheckProblem;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class RrdCheckRoutesTest extends TestCase
{
    private Kernel $kernel;
    private RrdCheckStore&MockObject $store;
    private RrdCheckPreferences&MockObject $preferences;
    private ?Actor $actor;
    private bool $denied = false;
    private bool $purgeFails = false;
    /** @var list<RrdCheckFilters> */
    private array $listed = [];
    /** @var list<array<string, string>> */
    private array $saved = [];
    /** @var list<int> */
    private array $purged = [];

    protected function setUp(): void
    {
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        $this->actor = new Actor(42, 'operator');
        $console = $this->createMock(ConsoleAccess::class);
        $console->method('consoleActor')->willReturnCallback(fn(): ?Actor => $this->actor);
        $container->set(ConsoleAccess::class, $console);
        $access = $this->createMock(RrdCheckAccess::class);
        $access->method('authorize')->willReturnCallback(fn(): Actor => $this->denied ? throw new RrdCheckAccessDenied(false) : $this->actor);
        $container->set(RrdCheckAccess::class, $access);
        $this->store = $this->createMock(RrdCheckStore::class);
        $this->store->method('defaultRows')->willReturn(25);
        $this->store->method('enabled')->willReturn(true);
        $this->store->method('count')->willReturn(3);
        $this->store->method('list')->willReturnCallback(function (RrdCheckFilters $filters): RrdCheckPage {
            $this->listed[] = $filters;
            return new RrdCheckPage([
                new RrdCheckProblem(7, 'Router <b>', null, 'RRDfile is not writable - /rra/<x>.rrd', '2026-10-07 10:00:00'),
            ], 30, $filters);
        });
        $this->store->method('purge')->willReturnCallback(function (int $actorId): int {
            if ($this->purgeFails) {
                throw new \RuntimeException('lock wait timeout');
            }
            $this->purged[] = $actorId;
            return 3;
        });
        $container->set(RrdCheckStore::class, $this->store);
        $this->preferences = $this->createMock(RrdCheckPreferences::class);
        $this->preferences->method('save')->willReturnCallback(function (array $filters): void {
            $this->saved[] = $filters;
        });
        $container->set(RrdCheckPreferences::class, $this->preferences);
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);
        $container->set(LegacyConfiguration::class, $configuration);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testAnonymousAndUnauthorizedRequestsStopBeforeTheStore(): void
    {
        $paths = ['/utilities/rrd-check?filter[]=x', '/utilities/rrd-check/purge', '/utilities/rrd-check/legacy?action=purge'];
        $this->actor = null;
        foreach ($paths as $path) {
            self::assertSame(401, $this->get($path)->getStatusCode(), $path);
        }
        $this->actor = new Actor(42, 'operator');
        $this->denied = true;
        foreach ($paths as $path) {
            self::assertSame(403, $this->get($path)->getStatusCode(), $path);
        }
        self::assertSame(403, $this->post([])->getStatusCode());
        self::assertSame([], $this->listed);
        self::assertSame([], $this->purged);
    }

    public function testListEscapesStoredTextAndKeepsTheFilters(): void
    {
        $response = $this->get('/utilities/rrd-check?filter=router&age=86400&rows=10&sort_column=message&sort_direction=DESC');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $html = (string) $response->getContent();
        self::assertStringContainsString('Router &lt;b&gt;', $html);
        self::assertStringContainsString('/rra/&lt;x&gt;.rrd', $html);
        self::assertStringContainsString('<td>Deleted</td>', $html);
        self::assertStringContainsString('<option value="86400" selected>', $html);
        self::assertStringContainsString('<option value="10" selected>', $html);
        self::assertStringContainsString('class="filterTable"', $html);
        self::assertStringContainsString('class="cactiTable"', $html);
        self::assertStringContainsString('rel="next"', $html);
        $filters = $this->listed[0];
        self::assertSame(['router', 86400, 10, 'message', 'DESC'], [$filters->filter, $filters->age, $filters->rows, $filters->sortColumn, $filters->sortDirection]);
        self::assertSame([['filter' => 'router', 'rows' => '10', 'page' => '1', 'sort_column' => 'message', 'sort_direction' => 'DESC', 'age' => '86400']], $this->saved);
    }

    public function testInvalidFiltersAreRejected(): void
    {
        foreach (['age=60', 'sort_column=rc.message', 'sort_direction=sideways', 'rows=0', 'page=0', 'filter[]=x', 'clear=2', 'unknown=1'] as $query) {
            self::assertSame(400, $this->get('/utilities/rrd-check?' . $query)->getStatusCode(), $query);
        }
        self::assertSame([], $this->listed);
    }

    public function testPlainVisitUsesSavedFiltersAndClearResetsThem(): void
    {
        $this->preferences->expects(self::once())->method('load')->willReturn(['filter' => 'saved', 'age' => '14400']);
        self::assertSame(200, $this->get('/utilities/rrd-check')->getStatusCode());
        self::assertSame(['saved', 14400], [$this->listed[0]->filter, $this->listed[0]->age]);
        self::assertSame([], $this->saved);
        self::assertSame(200, $this->get('/utilities/rrd-check?clear=1')->getStatusCode());
        self::assertSame(['', 0], [$this->listed[1]->filter, $this->listed[1]->age]);
        self::assertSame('', $this->saved[0]['filter']);
    }

    public function testPurgeNeedsAPostWithAValidToken(): void
    {
        $confirmation = $this->get('/utilities/rrd-check/purge');
        self::assertSame(200, $confirmation->getStatusCode());
        self::assertStringContainsString('delete all 3 recorded', (string) $confirmation->getContent());
        self::assertStringContainsString('name="rrd_check_purge[_token]"', (string) $confirmation->getContent());
        self::assertSame(422, $this->post([])->getStatusCode());
        self::assertSame(422, $this->post(['_token' => 'csrf-token'], 'https://attacker.invalid')->getStatusCode());
        self::assertSame(422, $this->post(['_token' => 'csrf-token', 'extra' => '1'])->getStatusCode());
        self::assertSame([], $this->purged);

        $response = $this->post(['_token' => 'csrf-token']);
        self::assertSame(303, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('/utilities/rrd-check?purged=1', $response->headers->get('Location'));
        self::assertSame([42], $this->purged);
    }

    public function testPurgeFailureIsReportedAsUncertain(): void
    {
        $this->purgeFails = true;
        $response = $this->post(['_token' => 'csrf-token']);
        self::assertSame(502, $response->getStatusCode());
        self::assertStringContainsString('Purge outcome is uncertain', (string) $response->getContent());
        self::assertStringNotContainsString('lock wait timeout', (string) $response->getContent());
    }

    public function testLegacyEntryNavigatesAndNeverPurges(): void
    {
        self::assertSame(409, $this->kernel->handle(Request::create('/utilities/rrd-check/legacy', 'POST', ['action' => 'purge']))->getStatusCode());
        self::assertSame('/utilities/rrd-check/purge', $this->get('/utilities/rrd-check/legacy?action=purge&header=false')->headers->get('Location'));
        self::assertSame('/utilities/rrd-check?clear=1', $this->get('/utilities/rrd-check/legacy?header=false&clear=1')->headers->get('Location'));
        self::assertSame('/utilities/rrd-check', $this->get('/utilities/rrd-check/legacy')->headers->get('Location'));
        $location = (string) $this->get('/utilities/rrd-check/legacy?header=false&filter=disk&age=43200&rows=-1')->headers->get('Location');
        self::assertStringContainsString('filter=disk', $location);
        self::assertStringContainsString('age=43200', $location);
        foreach (['action=delete', 'action[]=purge', 'clear=2', 'age=5'] as $query) {
            self::assertSame(400, $this->get('/utilities/rrd-check/legacy?' . $query)->getStatusCode(), $query);
        }
        self::assertSame([], $this->purged);
    }

    private function get(string $uri): Response
    {
        return $this->kernel->handle(Request::create($uri));
    }

    /** @param array<string, string> $fields */
    private function post(array $fields, string $origin = 'http://localhost'): Response
    {
        return $this->kernel->handle(Request::create('/utilities/rrd-check/purge', 'POST', $fields === [] ? [] : ['rrd_check_purge' => $fields], server: ['HTTP_ORIGIN' => $origin]));
    }
}
