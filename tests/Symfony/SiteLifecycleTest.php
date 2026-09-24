<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Command\DeleteSites;
use Kadupul\Inventory\Application\Command\DuplicateSites;
use Kadupul\Inventory\Application\Port\SiteLifecycle;
use Kadupul\Inventory\Application\Query\PrepareSiteAction;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\Site;
use Kadupul\Inventory\Domain\NewSite;
use Kadupul\Inventory\Domain\SiteSelection;
use Kadupul\Inventory\Domain\SiteEditConflict;
use Kadupul\Inventory\Infrastructure\Legacy\LegacySiteLifecycle;
use Kadupul\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class SiteLifecycleTest extends TestCase
{
    public function testEveryFieldParticipatesInTheRevision(): void
    {
        $site = new Site(7, 'Original', '');
        foreach (array_keys(NewSite::DEFAULTS) as $key) {
            $fields = $site->fields();
            $fields[$key] = $key === 'timezone' ? 'UTC' : (in_array($key, ['latitude', 'longitude', 'zoom'], true) ? '2' : 'Changed');
            $copy = clone $site;
            $copy->revise($fields['name'], $fields['notes'], $site->revision(), $fields);
            self::assertNotSame($site->revision(), $copy->revision(), $key);
        }
    }

    public function testDuplicationRuleCopiesSettingsWithoutChangingTheSource(): void
    {
        $site = new Site(7, 'Tokyo', 'Notes', ['city' => 'Tokyo', 'timezone' => 'Asia/Tokyo']);
        $before = $site->revision();
        $copy = $site->duplicate('<site> backup');
        self::assertSame('Tokyo backup', $copy->fields['name']);
        self::assertSame('Asia/Tokyo', $copy->fields['timezone']);
        self::assertSame($before, $site->revision());
        $this->expectException(\InvalidArgumentException::class);
        $site->duplicate(str_repeat('x', 101));
    }

    public function testInvalidFullEditDoesNotPartiallyMutate(): void
    {
        $site = new Site(7, 'Original', '');
        $revision = $site->revision();
        try {
            $site->revise('Changed', 'Changed', $revision, ['latitude' => '91']);
            self::fail('Invalid coordinate accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame($revision, $site->revision());
        }
    }

    public static function invalidSelections(): iterable
    {
        foreach ([[], [0], [-1], ['01'], ['1e2'], [1, '1'], [4294967296], [[1]], range(1, 101)] as $ids) {
            yield [$ids];
        }
    }

    #[DataProvider('invalidSelections')]
    public function testSelectionsAreBoundedAndRejectAmbiguousIds(array $ids): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SiteSelection::validateIds($ids);
    }

    public function testSelectionOrdersLocksDeterministically(): void
    {
        $selection = new SiteSelection([12 => str_repeat('a', 64), 2 => str_repeat('b', 64)]);
        self::assertSame([2, 12], array_keys($selection->revisions));
    }

    public function testApplicationUseCasesAuthorizeBeforePersistence(): void
    {
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn(null);
        $sites = $this->createMock(SiteLifecycle::class);
        $sites->expects(self::never())->method('find');
        $sites->expects(self::never())->method('delete');
        $sites->expects(self::never())->method('duplicate');
        foreach ([new PrepareSiteAction($access, $sites), new DeleteSites($access, $sites), new DuplicateSites($access, $sites)] as $command) {
            try {
                $command($command instanceof PrepareSiteAction ? [7] : new SiteSelection([7 => str_repeat('a', 64)]), '<site> copy');
                self::fail('Anonymous operation accepted');
            } catch (InventoryAccessDenied $error) {
                self::assertTrue($error->unauthenticated);
            }
        }
    }

    public function testConfirmationRejectsCsrfTamperingAndStaleSnapshots(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
            $access->method('canManageDevices')->willReturn(true);
            $container->set(ConsoleAccess::class, $access);
            $site = new Site(7, '<Site>', '');
            $sites = $this->createMock(SiteLifecycle::class);
            $sites->method('find')->willReturn([$site]);
            $sites->expects(self::once())->method('delete')->willThrowException(new SiteEditConflict('This site changed. Reload it before saving.'));
            $container->set(SiteLifecycle::class, $sites);
            $path = '/inventory/sites/delete?ids[]=7';
            $page = $kernel->handle(Request::create($path));
            self::assertSame(200, $page->getStatusCode());
            self::assertStringContainsString('&lt;Site&gt;', $page->getContent());
            $document = new \DOMDocument();
            @$document->loadHTML($page->getContent());
            $xpath = new \DOMXPath($document);
            $data = ['selection' => json_encode([7 => $site->revision()]), '_token' => $xpath->evaluate('string(//input[@name="site_action[_token]"]/@value)')];
            foreach ([false, true] as $origin) {
                $request = Request::create($path, 'POST', ['site_action' => $data]);
                if ($origin) {
                    $request->headers->set('Origin', 'http://localhost');
                }
                $response = $kernel->handle($request);
                self::assertSame($origin ? 409 : 422, $response->getStatusCode());
                self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            }
            $request = Request::create($path, 'POST', ['site_action' => array_replace($data, ['selection' => json_encode([8 => $site->revision()])])]);
            $request->headers->set('Origin', 'http://localhost');
            self::assertSame(422, $kernel->handle($request)->getStatusCode());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testCollectorExceptionRequiresAnActualSitesRoute(): void
    {
        $directory = sys_get_temp_dir() . '/kadupul-site-config-' . bin2hex(random_bytes(8));
        mkdir($directory . '/include', 0700, true);
        file_put_contents($directory . '/include/config.php', '<?php $poller_id = 2;');
        try {
            foreach ([null, 'inventory_devices', 'inventory_site_action'] as $route) {
                $stack = new \Symfony\Component\HttpFoundation\RequestStack();
                if ($route !== null) {
                    $request = Request::create('/?route=inventory_sites');
                    $request->attributes->set('_route', $route);
                    $stack->push($request);
                }
                try {
                    (new \Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration($directory, $stack))->values();
                    self::fail('Collector configuration was accepted');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString($route === 'inventory_site_action' ? 'Online primary configuration' : 'outside online collector Sites routes', $error->getMessage());
                }
            }
        } finally {
            unlink($directory . '/include/config.php');
            rmdir($directory . '/include');
            rmdir($directory);
        }
    }

    public function testCompatibilityRouteNeverReplaysLegacyMutations(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
            $access->method('canManageDevices')->willReturn(true);
            $kernel->getContainer()->get('test.service_container')->set(ConsoleAccess::class, $access);
            $response = $kernel->handle(Request::create('/inventory/sites/legacy', 'POST', ['action' => 'actions', 'selected_items' => 'malicious serialized input']));
            self::assertSame(409, $response->getStatusCode());
            $response = $kernel->handle(Request::create('/inventory/sites/legacy?action=edit&id=7'));
            self::assertSame('/inventory/sites/7/edit', $response->headers->get('Location'));
            $response = $kernel->handle(Request::create('/inventory/sites/legacy?action=edit&id=0'));
            self::assertSame('/inventory/sites/new', $response->headers->get('Location'));
            self::assertSame(400, $kernel->handle(Request::create('/inventory/sites/legacy?action=edit&id[]=7'))->getStatusCode());
            self::assertSame(405, $kernel->handle(Request::create('/inventory/sites/legacy?action=save'))->getStatusCode());
        } finally {
            $kernel->shutdown();
        }
    }

    public static function auditedOperations(): iterable
    {
        yield 'authorized deletion' => ['delete', 'success', 'allowed', 'succeeded'];
        yield 'authorized duplication' => ['duplicate', 'success', 'allowed', 'succeeded'];
        yield 'deletion revoked at persistence' => ['delete', 'revoked', 'denied', 'denied'];
        yield 'duplication revoked at persistence' => ['duplicate', 'revoked', 'denied', 'denied'];
        yield 'stale deletion after authorization' => ['delete', 'stale', 'allowed', 'failed'];
        yield 'duplication error after authorization' => ['duplicate', 'failure', 'allowed', 'failed'];
        yield 'deletion error after authorization' => ['delete', 'failure', 'allowed', 'failed'];
    }

    #[DataProvider('auditedOperations')]
    public function testBulkAuditRecordsEachSiteOnlyAfterResolution(string $operation, string $mode, string $decision, string $outcome): void
    {
        $fixture = new SiteAuditFixture();
        $fixture->writer->exec("INSERT INTO sites (id, name, city, zoom) VALUES (2, 'Second', 'Paris', '12'), (5, 'Fifth', 'Tokyo', '12'); INSERT INTO host (id, site_id) VALUES (1, 2)");
        $fixture->allowed = $mode !== 'revoked';
        $sites = new LegacySiteLifecycle($fixture->connection, $fixture->access(), $fixture->audit());
        $revisions = [];
        foreach ($sites->find([5, 2]) as $site) {
            $revisions[$site->id] = $site->revision();
        }
        if ($mode === 'stale') {
            $fixture->observer->exec("UPDATE sites SET city = 'Lyon' WHERE id = 5");
        }
        if ($mode === 'failure') {
            $fixture->failOn('INSERT', 'settings');
        }
        $before = $fixture->observer->query('SELECT id, name, city FROM sites ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        try {
            if ($operation === 'delete') {
                $sites->delete(42, new SiteSelection($revisions));
            } else {
                self::assertSame([6, 7], $sites->duplicate(42, new SiteSelection($revisions), 'password=hunter2-audit-marker <site>'));
            }
            self::assertSame('success', $mode);
        } catch (InventoryAccessDenied) {
            self::assertSame('revoked', $mode);
        } catch (SiteEditConflict) {
            self::assertSame('stale', $mode);
        } catch (\PDOException $error) {
            self::assertSame('failure', $mode);
            self::assertStringContainsString('private-failure-marker', $error->getMessage());
        }
        $fixture->assertRecords('inventory.site.' . $operation, ['2', '5'], $decision, $outcome, ['hunter2-audit-marker', 'Second', 'Fifth']);
        $committed = $before;
        if ($mode === 'success') {
            $committed = $operation === 'delete' ? [] : [...$before, ['id' => 6, 'name' => 'password=hunter2-audit-marker Second', 'city' => 'Paris'], ['id' => 7, 'name' => 'password=hunter2-audit-marker Fifth', 'city' => 'Tokyo']];
        }
        foreach ($fixture->records as $record) {
            self::assertSame($committed, $record['sites']);
        }
    }
}
