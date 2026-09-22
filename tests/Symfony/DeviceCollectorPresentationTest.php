<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Kernel;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceCollectorAssignments;
use Kadupul\Inventory\Domain\DeviceCollectorAssignment;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DeviceCollectorPresentationTest extends TestCase
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
            $device = new DeviceCollectorAssignment(7, '<router>', 4, 0);
            $port = $this->createMock(DeviceCollectorAssignments::class);
            $port->method('findVisible')->willReturn($device);
            $port->method('collectors')->willReturn([1 => 'Primary', 2 => '<Collector>', 3 => '<Collector>']);
            $port->expects(self::once())->method('save')->with(42, self::callback(fn($saved) => $saved->collectorId() === 2), $device->revision());
            $container->set(DeviceCollectorAssignments::class, $port);
            $path = '/inventory/devices/7/collector';
            $response = $kernel->handle(Request::create($path, 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('Affecter le collecteur de l’appareil', $response->getContent());
            self::assertStringContainsString('&lt;router&gt;', $response->getContent());
            self::assertStringContainsString('value="2">&lt;Collector&gt;', $response->getContent());
            self::assertStringContainsString('value="3">&lt;Collector&gt;', $response->getContent());
            self::assertStringNotContainsString('value="4"', $response->getContent());
            $document = new \DOMDocument();
            @$document->loadHTML($response->getContent());
            $token = (new \DOMXPath($document))->evaluate('string(//input[@name="device_collector[_token]"]/@value)');
            $fields = ['collector_id' => '2', 'revision' => $device->revision(), '_token' => $token];
            foreach (['', '9999', null] as $invalid) {
                $data = array_replace($fields, ['collector_id' => $invalid]);
                $request = Request::create($path, 'POST', ['device_collector' => $data], ['Cacti' => 'fixture']);
                $request->headers->set('Origin', 'http://localhost');
                $response = $kernel->handle($request);
                self::assertSame(422, $response->getStatusCode());
                self::assertStringContainsString('Sélectionnez un collecteur valide.', $response->getContent());
            }
            $request = Request::create($path, 'POST', ['device_collector' => $fields], ['Cacti' => 'fixture']);
            $request->headers->set('Origin', 'http://localhost');
            self::assertSame(303, $kernel->handle($request)->getStatusCode());
        } finally {
            $kernel->shutdown();
        }
    }
}
