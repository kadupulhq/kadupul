<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Kernel;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceStates;
use Kadupul\Inventory\Domain\DeviceState;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DeviceBulkAssignmentPresentationTest extends TestCase
{
    public function testUnauthorizedGetAndPostDoNotReadOrAssignDevices(): void
    {
        foreach ([null, new Actor(42, 'operator')] as $actor) {
            $kernel = new Kernel('test', true);
            try {
                $kernel->boot();
                $container = $kernel->getContainer()->get('test.service_container');
                $this->configureInstallation($container);
                $access = $this->createMock(ConsoleAccess::class);
                $access->method('consoleActor')->willReturn($actor);
                $access->method('canManageDevices')->willReturn(false);
                $container->set(ConsoleAccess::class, $access);
                $port = $this->createMockForIntersectionOfInterfaces([DeviceStates::class, \Kadupul\Inventory\Application\Port\DeviceBulkAssignments::class]);
                $port->expects(self::never())->method('findVisible');
                $port->expects(self::never())->method('assign');
                $container->set(DeviceStates::class, $port);
                foreach (['GET', 'POST'] as $method) {
                    self::assertSame($actor === null ? 401 : 403, $kernel->handle(Request::create('/inventory/devices/assign/site?ids[]=7', $method, [], ['Cacti' => 'fixture']))->getStatusCode());
                }
            } finally {
                $kernel->shutdown();
            }
        }
    }

    public function testMissingDevicesAndUnknownRouteKindAreNotFound(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $this->configureInstallation($container);
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
            $access->method('canManageDevices')->willReturn(true);
            $container->set(ConsoleAccess::class, $access);
            $port = $this->createMockForIntersectionOfInterfaces([DeviceStates::class, \Kadupul\Inventory\Application\Port\DeviceBulkAssignments::class]);
            $port->method('findVisible')->willThrowException(new \Kadupul\Inventory\Application\Command\DevicesNotFound());
            $port->expects(self::never())->method('assign');
            $container->set(DeviceStates::class, $port);
            foreach (['GET', 'POST'] as $method) {
                self::assertSame(404, $kernel->handle(Request::create('/inventory/devices/assign/site?ids[]=7', $method, [], ['Cacti' => 'fixture']))->getStatusCode());
            }
            self::assertSame(404, $kernel->handle(Request::create('/inventory/devices/assign/unknown?ids[]=7'))->getStatusCode());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testTemplateAndCollectorChoicesRenderTheirSupportedUnassignmentContract(): void
    {
        foreach (['template', 'collector'] as $kind) {
            $kernel = new Kernel('test', true);
            try {
                $kernel->boot();
                $container = $kernel->getContainer()->get('test.service_container');
                $this->configureInstallation($container);
                $access = $this->createMock(ConsoleAccess::class);
                $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
                $access->method('canManageDevices')->willReturn(true);
                $container->set(ConsoleAccess::class, $access);
                $port = $this->createMockForIntersectionOfInterfaces([DeviceStates::class, \Kadupul\Inventory\Application\Port\DeviceBulkAssignments::class]);
                $port->method('findVisible')->willReturn([new DeviceState(7, 'Router', 'router.invalid', true, 0, 1, 0)]);
                $port->expects(self::never())->method('assign');
                $container->set(DeviceStates::class, $port);
                $interface = $kind === 'template' ? \Kadupul\Inventory\Application\Port\DeviceTemplateAssignments::class : \Kadupul\Inventory\Application\Port\DeviceCollectorAssignments::class;
                $catalog = $this->createMock($interface);
                $catalog->method($kind === 'template' ? 'templates' : 'collectors')->willReturn([3 => '<target>']);
                $container->set($interface, $catalog);
                $response = $kernel->handle(Request::create('/inventory/devices/assign/' . $kind . '?ids[]=7', 'GET', [], ['Cacti' => 'fixture']));
                self::assertSame(200, $response->getStatusCode());
                $document = new \DOMDocument();
                @$document->loadHTML($response->getContent());
                $xpath = new \DOMXPath($document);
                self::assertSame($kind === 'template' ? 1.0 : 0.0, $xpath->evaluate('count(//select[@name="device_bulk_assignment[target]"]/option[@value="0"])'));
                self::assertStringContainsString('&lt;target&gt;', $response->getContent());
            } finally {
                $kernel->shutdown();
            }
        }
    }

    private function configureInstallation(object $container): void
    {
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['forced_locale' => 'en-US']);
        $container->set(LegacyConfiguration::class, $configuration);
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $container->set(DatabaseConnection::class, $database);
    }

    public function testFrenchPresentationEscapesNamesAndPreservesAssignmentValues(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $configuration = $this->createMock(LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['forced_locale' => 'fr-FR']);
            $container->set(LegacyConfiguration::class, $configuration);
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
            $database = $this->createMock(DatabaseConnection::class);
            $database->method('get')->willReturn($pdo);
            $container->set(DatabaseConnection::class, $database);
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
            $access->method('canManageDevices')->willReturn(true);
            $container->set(ConsoleAccess::class, $access);
            $device = new DeviceState(7, '<router>', 'router.invalid', true, 0, 1, 0);
            $port = $this->createMockForIntersectionOfInterfaces([DeviceStates::class, \Kadupul\Inventory\Application\Port\DeviceStatistics::class, \Kadupul\Inventory\Application\Port\DeviceTemplateSynchronization::class, \Kadupul\Inventory\Application\Port\DeviceOptions::class, \Kadupul\Inventory\Application\Port\DeviceBulkAssignments::class]);
            $port->method('findVisible')->willReturn([$device]);
            $port->expects(self::once())->method('assign')->with(42, self::callback(fn($selection) => $selection->revisions === [7 => $device->revision()]), self::callback(fn($change) => $change->kind === 'site' && $change->targetId === 3));
            $container->set(DeviceStates::class, $port);
            $sites = $this->createMock(\Kadupul\Inventory\Application\Port\SiteAssignmentCatalog::class);
            $sites->method('sites')->willReturn([3 => '<HQ>']);
            $container->set(\Kadupul\Inventory\Application\Port\SiteAssignmentCatalog::class, $sites);
            $path = '/inventory/devices/assign/site?ids[]=7';
            $response = $kernel->handle(Request::create($path, 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('Affecter les sites des appareils', $response->getContent());
            self::assertStringContainsString('&lt;router&gt;', $response->getContent());
            $document = new \DOMDocument();
            @$document->loadHTML($response->getContent());
            $token = (new \DOMXPath($document))->evaluate('string(//input[@name="device_bulk_assignment[_token]"]/@value)');
            $fields = ['selection' => json_encode([7 => $device->revision()]), '_token' => $token, 'target' => '3'];
            foreach (['', 'null', '{}', '{"8":"' . $device->revision() . '"}'] as $invalid) {
                $request = Request::create($path, 'POST', ['device_bulk_assignment' => array_replace($fields, ['selection' => $invalid])], ['Cacti' => 'fixture']);
                $request->headers->set('Origin', 'http://localhost');
                self::assertSame(422, $kernel->handle($request)->getStatusCode());
            }
            foreach (['', '999999', ['3'], '3.5'] as $target) {
                $request = Request::create($path, 'POST', ['device_bulk_assignment' => array_replace($fields, ['target' => $target])], ['Cacti' => 'fixture']);
                $request->headers->set('Origin', 'http://localhost');
                self::assertSame(422, $kernel->handle($request)->getStatusCode());
            }
            $request = Request::create($path, 'POST', ['device_bulk_assignment' => $fields + ['poller_id' => '2']], ['Cacti' => 'fixture']);
            $request->headers->set('Origin', 'http://localhost');
            self::assertSame(422, $kernel->handle($request)->getStatusCode());
            $request = Request::create($path, 'POST', ['device_bulk_assignment' => $fields], ['Cacti' => 'fixture']);
            $request->headers->set('Origin', 'http://localhost');
            self::assertSame(303, $kernel->handle($request)->getStatusCode());
        } finally {
            $kernel->shutdown();
        }
    }
}
