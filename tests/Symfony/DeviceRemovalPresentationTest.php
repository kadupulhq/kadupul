<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Kernel;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceRemovals;
use Kadupul\Inventory\Domain\DeviceState;
use Kadupul\Inventory\Domain\DeviceRemoval;
use Kadupul\Inventory\Domain\DeviceRemovalPolicy;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DeviceRemovalPresentationTest extends TestCase
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
            $device = new DeviceRemoval(new DeviceState(7, '<router>', 'router.invalid', true, 0, 1, 0), [11], [12]);
            $port = $this->createMock(DeviceRemovals::class);
            $port->method('findVisible')->willReturn([$device]);
            $port->expects(self::once())->method('remove')->with(42, self::callback(fn($selection) => $selection->revisions === [7 => $device->revision()]), DeviceRemovalPolicy::Retain);
            $container->set(DeviceRemovals::class, $port);
            $path = '/inventory/devices/remove?ids[]=7';
            $response = $kernel->handle(Request::create($path, 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('Supprimer les appareils', $response->getContent());
            self::assertStringContainsString('&lt;router&gt;', $response->getContent());
            $document = new \DOMDocument();
            @$document->loadHTML($response->getContent());
            $token = (new \DOMXPath($document))->evaluate('string(//input[@name="device_removal[_token]"]/@value)');
            $fields = ['selection' => json_encode([7 => $device->revision()]), '_token' => $token, 'policy' => 'retain'];
            foreach (['', 'null', '{}', '{"8":"' . $device->revision() . '"}'] as $invalid) {
                $request = Request::create($path, 'POST', ['device_removal' => array_replace($fields, ['selection' => $invalid])], ['Cacti' => 'fixture']);
                $request->headers->set('Origin', 'http://localhost');
                self::assertSame(422, $kernel->handle($request)->getStatusCode());
            }
            foreach (['', 'invalid', null] as $invalid) {
                $request = Request::create($path, 'POST', ['device_removal' => array_replace($fields, ['policy' => $invalid])], ['Cacti' => 'fixture']);
                $request->headers->set('Origin', 'http://localhost');
                self::assertSame(422, $kernel->handle($request)->getStatusCode());
            }
            $request = Request::create($path, 'POST', ['device_removal' => $fields], ['Cacti' => 'fixture']);
            $request->headers->set('Origin', 'http://localhost');
            self::assertSame(303, $kernel->handle($request)->getStatusCode());
        } finally {
            $kernel->shutdown();
        }
    }
}
