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

final class DeviceTemplateSynchronizationPresentationTest extends TestCase
{
    public function testSynchronizationPresentationDispatchAndCompletion(): void
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
            $port = $this->createMockForIntersectionOfInterfaces([DeviceStates::class, \Kadupul\Inventory\Application\Port\DeviceStatistics::class, \Kadupul\Inventory\Application\Port\DeviceTemplateSynchronization::class, \Kadupul\Inventory\Application\Port\DeviceOptions::class, \Kadupul\Inventory\Application\Port\DeviceSnmpSettings::class]);
            $port->method('findVisible')->willReturn([$device]);
            $port->expects(self::once())->method('synchronizeTemplates')->with(42, self::callback(fn($selection) => $selection->revisions === [7 => $device->revision()]));
            $port->expects(self::never())->method('setEnabled');
            $port->expects(self::never())->method('clearStatistics');
            $port->expects(self::never())->method('changeOptions');
            $port->expects(self::never())->method('changeSnmp');
            $container->set(DeviceStates::class, $port);
            $path = '/inventory/devices/sync-template?ids[]=7';
            $response = $kernel->handle(Request::create($path, 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('Synchroniser les modèles des appareils', $response->getContent());
            self::assertStringContainsString('&lt;router&gt;', $response->getContent());
            self::assertStringNotContainsString('<router>', $response->getContent());
            self::assertStringContainsString('Appliquez les modèles actuellement affectés', $response->getContent());
            self::assertStringContainsString('Confirmer la synchronisation', $response->getContent());
            self::assertStringContainsString('<title>Synchroniser les modèles des appareils', $response->getContent());
            $document = new \DOMDocument();
            @$document->loadHTML($response->getContent());
            $token = (new \DOMXPath($document))->evaluate('string(//input[@name="device_state[_token]"]/@value)');
            $fields = ['selection' => json_encode([7 => $device->revision()]), '_token' => $token];
            foreach (['', 'null', '{}', '{"8":"' . $device->revision() . '"}'] as $invalid) {
                $request = Request::create($path, 'POST', ['device_state' => array_replace($fields, ['selection' => $invalid])], ['Cacti' => 'fixture']);
                $request->headers->set('Origin', 'http://localhost');
                self::assertSame(422, $kernel->handle($request)->getStatusCode());
            }
            $request = Request::create($path, 'POST', ['device_state' => $fields], ['Cacti' => 'fixture']);
            $request->headers->set('Origin', 'http://localhost');
            $redirect = $kernel->handle($request);
            self::assertSame(303, $redirect->getStatusCode());
            self::assertStringContainsString('completed=sync-template', $redirect->headers->get('Location'));
            $catalog = $this->createMock(\Kadupul\Inventory\Application\Port\DeviceCatalog::class);
            $catalog->method('visibleTo')->willReturn(new \Kadupul\Inventory\Application\ReadModel\DevicePage([], false));
            $container->set(\Kadupul\Inventory\Application\Port\DeviceCatalog::class, $catalog);
            $sites = $this->createMock(\Kadupul\Inventory\Application\Port\DeviceSites::class);
            $sites->method('visibleTo')->willReturn([]);
            $container->set(\Kadupul\Inventory\Application\Port\DeviceSites::class, $sites);
            $completed = $kernel->handle(Request::create($redirect->headers->get('Location'), 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $completed->getStatusCode());
            self::assertSame(1, substr_count($completed->getContent(), 'Modèles des appareils sélectionnés synchronisés.'));
        } finally {
            $kernel->shutdown();
        }
    }
}
