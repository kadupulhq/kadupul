<?php

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

final class DeviceStatePresentationTest extends TestCase
{
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
            $port = $this->createMockForIntersectionOfInterfaces([DeviceStates::class, \Kadupul\Inventory\Application\Port\DeviceStatistics::class, \Kadupul\Inventory\Application\Port\DeviceTemplateSynchronization::class]);
            $port->method('findVisible')->willReturn([$device]);
            $port->expects(self::once())->method('setEnabled')->with(42, self::callback(fn($selection) => $selection->revisions === [7 => $device->revision()]), false);
            $container->set(DeviceStates::class, $port);
            $path = '/inventory/devices/disable?ids[]=7';
            $response = $kernel->handle(Request::create($path, 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('Désactiver les appareils', $response->getContent());
            self::assertStringContainsString('&lt;router&gt;', $response->getContent());
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
            self::assertSame(303, $kernel->handle($request)->getStatusCode());
        } finally {
            $kernel->shutdown();
        }
    }
}
