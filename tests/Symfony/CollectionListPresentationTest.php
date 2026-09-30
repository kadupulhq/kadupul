<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Collection\Application\Port\AutomationGraphRuleCatalog;
use Kadupul\Collection\Application\Port\AutomationTemplateCatalog;
use Kadupul\Collection\Application\Port\AutomationTreeRuleCatalog;
use Kadupul\Collection\Application\Port\DiscoveredDeviceCatalog;
use Kadupul\Collection\Application\Port\NetworkCatalog;
use Kadupul\Collection\Application\ReadModel\AutomationGraphRulePage;
use Kadupul\Collection\Application\ReadModel\AutomationGraphRuleSummary;
use Kadupul\Collection\Application\ReadModel\AutomationTemplatePage;
use Kadupul\Collection\Application\ReadModel\AutomationTemplateSummary;
use Kadupul\Collection\Application\ReadModel\AutomationTreeRulePage;
use Kadupul\Collection\Application\ReadModel\AutomationTreeRuleSummary;
use Kadupul\Collection\Application\ReadModel\DiscoveredDevicePage;
use Kadupul\Collection\Application\ReadModel\DiscoveredDeviceSummary;
use Kadupul\Collection\Application\ReadModel\DiscoveryNetworkChoice;
use Kadupul\Collection\Application\ReadModel\NetworkPage;
use Kadupul\Collection\Application\ReadModel\NetworkSummary;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CollectionListPresentationTest extends TestCase
{
    private const ROUTES = [
        '/automation/networks' => NetworkCatalog::class,
        '/automation/devices' => DiscoveredDeviceCatalog::class,
        '/automation/templates' => AutomationTemplateCatalog::class,
        '/automation/tree-rules' => AutomationTreeRuleCatalog::class,
        '/automation/graph-rules' => AutomationGraphRuleCatalog::class,
    ];

    public function testAllFiveRoutesDenyBeforeReadingCatalogs(): void
    {
        foreach (self::ROUTES as $path => $catalogType) {
            foreach ([false => 401, true => 403] as $authenticated => $expectedStatus) {
                $kernel = new Kernel('test', true);
                try {
                    $kernel->boot();
                    $container = $kernel->getContainer()->get('test.service_container');
                    $access = $this->createMock(ConsoleAccess::class);
                    $access->method('consoleActor')->willReturn($authenticated ? new Actor(42, 'operator') : null);
                    if ($authenticated) {
                        $access->expects(self::once())->method('canManageAutomation')->willReturn(false);
                    } else {
                        $access->expects(self::never())->method('canManageAutomation');
                    }
                    $container->set(ConsoleAccess::class, $access);
                    $catalog = $this->createMock($catalogType);
                    $catalog->expects(self::never())->method('list');
                    $container->set($catalogType, $catalog);

                    $response = $kernel->handle(Request::create($path));
                    self::assertSame($expectedStatus, $response->getStatusCode(), $path);
                    self::assertCacheIsPrivateAndNotStored($response->headers->get('Cache-Control'), $path);
                } finally {
                    $kernel->shutdown();
                }
            }
        }
    }

    public function testAllFiveRoutesRenderAuthenticatedDataAsEscapedHtml(): void
    {
        foreach (self::ROUTES as $path => $catalogType) {
            $kernel = new Kernel('test', true);
            try {
                $kernel->boot();
                $container = $kernel->getContainer()->get('test.service_container');
                $access = $this->createMock(ConsoleAccess::class);
                $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
                $access->method('canManageAutomation')->willReturn(true);
                $container->set(ConsoleAccess::class, $access);
                $catalog = $this->createMock($catalogType);
                $catalog->method('list')->willReturn($this->pageFor($catalogType));
                $container->set($catalogType, $catalog);
                $configuration = $this->createMock(LegacyConfiguration::class);
                $configuration->method('values')->willReturn(['url_path' => '/cacti/']);
                $container->set(LegacyConfiguration::class, $configuration);

                $response = $kernel->handle(Request::create($path));
                self::assertSame(200, $response->getStatusCode(), $path);
                self::assertCacheIsPrivateAndNotStored($response->headers->get('Cache-Control'), $path);
                self::assertStringContainsString('&lt;review-probe&gt;', $response->getContent(), $path);
                self::assertStringNotContainsString('<review-probe>', $response->getContent(), $path);
                $formNames = [
                    '/automation/networks' => 'network_filter',
                    '/automation/devices' => 'discovery_filter',
                    '/automation/templates' => 'automation_template_filter',
                    '/automation/tree-rules' => 'automation_tree_rule_filter',
                    '/automation/graph-rules' => 'automation_graph_rule_filter',
                ];
                $partial = $kernel->handle(Request::create($path, 'GET', [$formNames[$path] => ['q' => 'review']]));
                self::assertSame(200, $partial->getStatusCode(), $path . ' retains defaults for omitted GET form fields');
                self::assertStringContainsString('&lt;review-probe&gt;', $partial->getContent());
            } finally {
                $kernel->shutdown();
            }
        }
    }

    private function pageFor(string $catalogType): object
    {
        return match ($catalogType) {
            NetworkCatalog::class => new NetworkPage([new NetworkSummary(1, '<review-probe>', 'poller', 'Manual', 1, 'Idle', '0/0/0', 0, 0, 1, 0, null, null)], false),
            DiscoveredDeviceCatalog::class => new DiscoveredDevicePage([new DiscoveredDeviceSummary(1, '<review-probe>', '192.0.2.1', '', '', '', '', '', 0, false, false, '')], [new DiscoveryNetworkChoice(1, 'test')], [], false),
            AutomationTemplateCatalog::class => new AutomationTemplatePage([new AutomationTemplateSummary(1, '<review-probe>', 'Ping', '', '', '', 1)], false),
            AutomationTreeRuleCatalog::class => new AutomationTreeRulePage([new AutomationTreeRuleSummary(1, '<review-probe>', 'tree', '', 'Host', 'Site', true)], false),
            AutomationGraphRuleCatalog::class => new AutomationGraphRulePage([new AutomationGraphRuleSummary(1, '<review-probe>', '', 'Line', true)], false),
        };
    }

    private function assertCacheIsPrivateAndNotStored(?string $header, string $path): void
    {
        self::assertNotNull($header, $path);
        self::assertStringContainsString('private', $header, $path);
        self::assertStringContainsString('no-store', $header, $path);
    }
}
